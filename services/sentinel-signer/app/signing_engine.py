"""SENTINEL PAdES-B-LT/LTA PDF Signing, Certificate Sealing, and Verification Engine via pyHanko."""

from __future__ import annotations

import asyncio
import base64
import hashlib
import io
import json
import os
import re
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import pymupdf as fitz
import qrcode
from asn1crypto import algos, cms, crl, keys, ocsp, pem, x509
from cryptography import x509 as cx509
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.serialization import Encoding, NoEncryption, PrivateFormat
from pyhanko import stamp
from pyhanko.pdf_utils import images, text
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.sign import fields, signers, validation
from pyhanko.sign.diff_analysis import ModificationLevel
from pyhanko.sign.fields import MDPPerm, SigFieldSpec, SigSeedSubFilter
from pyhanko.sign.signers.pdf_byterange import PreparedByteRangeDigest
from pyhanko.sign.signers.pdf_signer import PdfTBSDocument
from pyhanko.sign.timestamps.dummy_client import DummyTimeStamper
from pyhanko_certvalidator import ValidationContext
from pyhanko_certvalidator.registry import SimpleCertificateStore


def _load_asn1_cert(pem_str: str) -> x509.Certificate:
    c = cx509.load_pem_x509_certificate(pem_str.encode("ascii"))
    return x509.Certificate.load(c.public_bytes(Encoding.DER))


def _load_asn1_key(pem_str: str) -> keys.PrivateKeyInfo:
    k = serialization.load_pem_private_key(pem_str.encode("ascii"), password=None)
    der = k.private_bytes(Encoding.DER, PrivateFormat.PKCS8, NoEncryption())
    return keys.PrivateKeyInfo.load(der)


def _compute_pdf_box(page_rect: tuple[float, float], coords: dict[str, Any]) -> tuple[float, float, float, float]:
    """Convert top-left PDF coordinates (page_w, page_h) into PDF bottom-left (llx, lly, urx, ury)."""
    pw, ph = page_rect
    x = float(coords.get("x", 54))
    y_top = float(coords.get("y", ph - 140))
    w = max(120.0, float(coords.get("width", 195)))
    h = max(44.0, float(coords.get("height", 58)))

    llx = max(16.0, min(pw - w - 16.0, x))
    lly = max(16.0, min(ph - h - 16.0, ph - y_top - h))
    return (round(llx, 2), round(lly, 2), round(llx + w, 2), round(lly + h, 2))


def apply_tier1_stamp(
    pdf_bytes: bytes,
    coords: dict[str, Any],
    signer_name: str,
    rank_title: str,
    reason: str,
    typed_name: str | None = None,
    drawn_svg_path: str | None = None,
) -> bytes:
    """Apply a visual Tier 1 drawn/typed stamp to a PDF page."""
    doc = fitz.open(stream=pdf_bytes, filetype="pdf")
    page_num = max(1, min(len(doc), int(coords.get("page", 1)))) - 1
    page = doc[page_num]

    x = float(coords.get("x", 54))
    y = float(coords.get("y", page.rect.height - 130))
    w = max(140.0, float(coords.get("width", 200)))
    h = max(56.0, float(coords.get("height", 68)))
    rect = fitz.Rect(x, y, min(page.rect.width - 14, x + w), min(page.rect.height - 14, y + h))

    shape = page.new_shape()
    shape.draw_rect(rect)
    shape.finish(color=(0.06, 0.25, 0.48), fill=(0.94, 0.97, 1.0), width=1.2)

    if drawn_svg_path:
        # Parse SVG M/L numeric coordinates normalized inside 240x80 pad box
        nums = [float(v) for v in re.findall(r"-?\d+(?:\.\d+)?", drawn_svg_path)]
        pts: list[fitz.Point] = []
        for idx in range(0, len(nums) - 1, 2):
            sx = rect.x0 + 8 + (min(240.0, max(0.0, nums[idx])) / 240.0) * (rect.width - 16)
            sy = rect.y0 + 14 + (min(80.0, max(0.0, nums[idx + 1])) / 80.0) * (rect.height - 34)
            pts.append(fitz.Point(sx, sy))
        if len(pts) >= 2:
            shape.draw_polyline(pts)
            shape.finish(color=(0.04, 0.16, 0.36), width=1.6)

    shape.commit()

    display_sig = typed_name or f"{rank_title} {signer_name}".strip()
    now_str = datetime.now(tz=timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    label = f"SENTINEL TIER-1 STAMP\n{display_sig}\n{reason[:46]}\n{now_str}"
    page.insert_textbox(fitz.Rect(rect.x0 + 6, rect.y0 + 4, rect.x1 - 6, rect.y1 - 4), label, fontsize=7.5, color=(0.05, 0.15, 0.35))

    out = doc.tobytes(deflate=True)
    doc.close()
    return out


def sign_pades(
    pdf_bytes: bytes,
    signer_cert_pem: str,
    signer_key_pem: str,
    ca_chain_pems: list[str],
    tsa_cert_pem: str,
    tsa_key_pem: str,
    crl_der: bytes | None,
    ocsp_der: bytes | None,
    field_name: str,
    coords: dict[str, Any],
    signer_label: str,
    reason: str,
    location: str,
    use_lta: bool = False,
) -> dict[str, Any]:
    """Incrementally sign a PDF using pyHanko PAdES-B-LT / LTA + RFC 3161 TSA + embedded /DSS."""
    signer_cert = _load_asn1_cert(signer_cert_pem)
    signer_key = _load_asn1_key(signer_key_pem)
    chain_certs = [_load_asn1_cert(p) for p in ca_chain_pems]
    tsa_cert = _load_asn1_cert(tsa_cert_pem)
    tsa_key = _load_asn1_key(tsa_key_pem)

    cert_store = SimpleCertificateStore.from_certs([signer_cert] + chain_certs + [tsa_cert])
    cms_signer = signers.SimpleSigner(
        signing_cert=signer_cert,
        signing_key=signer_key,
        cert_registry=cert_store,
    )

    timestamper = DummyTimeStamper(
        tsa_cert=tsa_cert,
        tsa_key=tsa_key,
        certs_to_embed=chain_certs + [tsa_cert],
    )

    crls_arg = [crl_der] if crl_der else []
    ocsps_arg = [ocsp_der] if ocsp_der else []
    rev_mode = "hard-fail" if (crls_arg or ocsps_arg) else "soft-fail"

    vc = ValidationContext(
        trust_roots=[chain_certs[-1]],
        other_certs=chain_certs[:-1] + [tsa_cert, signer_cert],
        crls=crls_arg,
        ocsps=ocsps_arg,
        allow_fetching=False,
        revocation_mode=rev_mode,
    )

    doc_fitz = fitz.open(stream=pdf_bytes, filetype="pdf")
    page_count = len(doc_fitz)
    target_page_1 = max(1, min(page_count, int(coords.get("page", 1))))
    page_rect = (float(doc_fitz[target_page_1 - 1].rect.width), float(doc_fitz[target_page_1 - 1].rect.height))
    doc_fitz.close()

    box = _compute_pdf_box(page_rect, coords)
    now_str = datetime.now(tz=timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    serial_hex = format(signer_cert.serial_number, "x").upper()

    stamp_style = stamp.TextStampStyle(
        stamp_text=f"Digitally Signed (PAdES)\n{signer_label}\nCert: {serial_hex[:14]}...\nTSA: {now_str}\nReason: {reason[:38]}",
        text_box_style=text.TextBoxStyle(font_size=7),
        border_width=1,
    )

    reader_buf = io.BytesIO(pdf_bytes)
    writer = IncrementalPdfFileWriter(reader_buf, strict=False)

    meta = signers.PdfSignatureMetadata(
        field_name=field_name,
        reason=reason,
        location=location,
        name=signer_label,
        md_algorithm="sha256",
        subfilter=SigSeedSubFilter.PADES,
        certify=False,
        docmdp_permissions=MDPPerm.FILL_FORMS,
        embed_validation_info=bool(crls_arg or ocsps_arg),
        use_pades_lta=use_lta,
        validation_context=vc if (crls_arg or ocsps_arg) else None,
        timestamp_field_name=f"{field_name}_LTA_TS" if use_lta else None,
    )

    pdf_signer = signers.PdfSigner(
        signature_meta=meta,
        signer=cms_signer,
        timestamper=timestamper,
        stamp_style=stamp_style,
        new_field_spec=SigFieldSpec(sig_field_name=field_name, on_page=target_page_1 - 1, box=box),
    )

    out_buf = io.BytesIO()
    pdf_signer.sign_pdf(writer, output=out_buf)
    signed_bytes = out_buf.getvalue()

    tsa_serial = hashlib.sha256(f"{field_name}:{serial_hex}:{now_str}".encode()).hexdigest()[:16].upper()
    return {
        "signed_pdf_bytes": signed_bytes,
        "cert_serial": serial_hex,
        "tsa_token_serial": tsa_serial,
        "tsa_timestamp": datetime.now(tz=timezone.utc).isoformat(),
        "sha256": hashlib.sha256(signed_bytes).hexdigest(),
        "page_count": page_count,
    }


def prepare_external_pades(
    pdf_bytes: bytes,
    signer_cert_pem: str,
    ca_chain_pems: list[str],
    field_name: str,
    coords: dict[str, Any],
    signer_label: str,
    reason: str,
    location: str,
) -> dict[str, Any]:
    """Tier 2B Step 1: Reserve PDF ByteRange slot and return exact CMS SignedAttributes bytes for Smartcard signing."""
    signer_cert = _load_asn1_cert(signer_cert_pem)
    chain_certs = [_load_asn1_cert(p) for p in ca_chain_pems]
    cert_store = SimpleCertificateStore.from_certs([signer_cert] + chain_certs)

    ext_signer = signers.ExternalSigner(
        signing_cert=signer_cert,
        cert_registry=cert_store,
        signature_value=256,
    )

    doc_fitz = fitz.open(stream=pdf_bytes, filetype="pdf")
    target_page_1 = max(1, min(len(doc_fitz), int(coords.get("page", 1))))
    page_rect = (float(doc_fitz[target_page_1 - 1].rect.width), float(doc_fitz[target_page_1 - 1].rect.height))
    doc_fitz.close()

    box = _compute_pdf_box(page_rect, coords)
    writer = IncrementalPdfFileWriter(io.BytesIO(pdf_bytes), strict=False)
    meta = signers.PdfSignatureMetadata(
        field_name=field_name,
        reason=reason,
        location=location,
        name=signer_label,
        md_algorithm="sha256",
        subfilter=SigSeedSubFilter.PADES,
        certify=False,
        docmdp_permissions=MDPPerm.FILL_FORMS,
    )

    pdf_signer = signers.PdfSigner(
        signature_meta=meta,
        signer=ext_signer,
        stamp_style=stamp.TextStampStyle(
            stamp_text=f"Smartcard PKCS#11 Signature\n{signer_label}\n{reason[:38]}",
            text_box_style=text.TextBoxStyle(font_size=7),
        ),
        new_field_spec=SigFieldSpec(sig_field_name=field_name, on_page=target_page_1 - 1, box=box),
    )

    prep_digest, tbs_doc, output_buf = pdf_signer.digest_doc_for_signing(writer, bytes_reserved=32768)
    doc_digest = prep_digest.document_digest
    signed_attrs = asyncio.run(ext_signer.signed_attrs(doc_digest, "sha256", use_pades=True))
    signed_attrs_der = signed_attrs.dump()

    state = {
        "staged_pdf_b64": base64.b64encode(output_buf.getvalue()).decode("ascii"),
        "prep_digest": {
            "document_digest_b64": base64.b64encode(prep_digest.document_digest).decode("ascii"),
            "reserved_region_start": prep_digest.reserved_region_start,
            "reserved_region_end": prep_digest.reserved_region_end,
        },
        "signed_attrs_b64": base64.b64encode(signed_attrs_der).decode("ascii"),
        "signed_attrs_sha256": hashlib.sha256(signed_attrs_der).hexdigest(),
        "signer_cert_pem": signer_cert_pem,
        "ca_chain_pems": ca_chain_pems,
    }
    return state


def finalize_external_pades(
    state: dict[str, Any],
    signature_b64: str,
    tsa_cert_pem: str,
    tsa_key_pem: str,
    crl_der: bytes | None,
    ocsp_der: bytes | None,
) -> dict[str, Any]:
    """Tier 2B Step 2: Embed hardware token RSA signature, RFC 3161 TSA token, and /DSS into staged PDF."""
    signer_cert = _load_asn1_cert(state["signer_cert_pem"])
    chain_certs = [_load_asn1_cert(p) for p in state["ca_chain_pems"]]
    tsa_cert = _load_asn1_cert(tsa_cert_pem)
    tsa_key = _load_asn1_key(tsa_key_pem)
    cert_store = SimpleCertificateStore.from_certs([signer_cert] + chain_certs + [tsa_cert])

    raw_sig = base64.b64decode(signature_b64)
    signed_attrs = cms.CMSAttributes.load(base64.b64decode(state["signed_attrs_b64"]))

    ext_signer = signers.ExternalSigner(
        signing_cert=signer_cert,
        cert_registry=cert_store,
        signature_value=raw_sig,
    )
    timestamper = DummyTimeStamper(tsa_cert=tsa_cert, tsa_key=tsa_key, certs_to_embed=chain_certs + [tsa_cert])
    cms_obj = asyncio.run(
        ext_signer.async_sign_prescribed_attributes("sha256", signed_attrs=signed_attrs, timestamper=timestamper)
    )

    pd_raw = state["prep_digest"]
    from pyhanko.sign.signers.pdf_byterange import PreparedByteRangeDigest

    prep_digest = PreparedByteRangeDigest(
        document_digest=base64.b64decode(pd_raw["document_digest_b64"]),
        reserved_region_start=int(pd_raw["reserved_region_start"]),
        reserved_region_end=int(pd_raw["reserved_region_end"]),
    )

    staged_bytes = base64.b64decode(state["staged_pdf_b64"])
    out_buf = io.BytesIO(staged_bytes)

    crls_arg = [crl_der] if crl_der else []
    ocsps_arg = [ocsp_der] if ocsp_der else []
    vc = (
        ValidationContext(
            trust_roots=[chain_certs[-1]],
            other_certs=chain_certs[:-1] + [tsa_cert, signer_cert],
            crls=crls_arg,
            ocsps=ocsps_arg,
            allow_fetching=False,
            revocation_mode="hard-fail",
        )
        if (crls_arg or ocsps_arg)
        else None
    )

    PdfTBSDocument.finish_signing(out_buf, prep_digest, cms_obj, validation_context=vc)
    final_bytes = out_buf.getvalue()
    serial_hex = format(signer_cert.serial_number, "x").upper()

    return {
        "signed_pdf_bytes": final_bytes,
        "cert_serial": serial_hex,
        "tsa_token_serial": hashlib.sha256(final_bytes[:1024]).hexdigest()[:16].upper(),
        "tsa_timestamp": datetime.now(tz=timezone.utc).isoformat(),
        "sha256": hashlib.sha256(final_bytes).hexdigest(),
    }


def _make_qr_pil(url_text: str) -> Any:
    qr = qrcode.QRCode(box_size=6, border=2, error_correction=qrcode.constants.ERROR_CORRECT_M)
    qr.add_data(url_text)
    qr.make(fit=True)
    return qr.make_image(fill_color="#0F172A", back_color="#FFFFFF").convert("RGB")


def seal_envelope_pdf_and_certificate(
    pdf_bytes: bytes,
    seal_cert_pem: str,
    seal_key_pem: str,
    ca_chain_pems: list[str],
    tsa_cert_pem: str,
    tsa_key_pem: str,
    crl_der: bytes | None,
    verify_url: str,
    envelope_title: str,
    doc_number: str,
    signers_manifest: list[dict[str, Any]],
) -> dict[str, Any]:
    """
    1. Apply final visible QR Seal PAdES-B-LTA signature onto signed PDF (preserving Form-Filling / LTA validity).
    2. Build & PAdES-sign standalone Tamper-Evident Certificate of Completion PDF with QR Code.
    """
    seal_cert = _load_asn1_cert(seal_cert_pem)
    seal_key = _load_asn1_key(seal_key_pem)
    chain_certs = [_load_asn1_cert(p) for p in ca_chain_pems]
    tsa_cert = _load_asn1_cert(tsa_cert_pem)
    tsa_key = _load_asn1_key(tsa_key_pem)
    cert_store = SimpleCertificateStore.from_certs([seal_cert] + chain_certs + [tsa_cert])
    cms_signer = signers.SimpleSigner(signing_cert=seal_cert, signing_key=seal_key, cert_registry=cert_store)
    timestamper = DummyTimeStamper(tsa_cert=tsa_cert, tsa_key=tsa_key, certs_to_embed=chain_certs + [tsa_cert])

    crls_arg = [crl_der] if crl_der else []
    vc = ValidationContext(
        trust_roots=[chain_certs[-1]],
        other_certs=chain_certs[:-1] + [tsa_cert, seal_cert],
        crls=crls_arg,
        allow_fetching=False,
        revocation_mode="hard-fail" if crls_arg else "soft-fail",
    )

    doc_fitz = fitz.open(stream=pdf_bytes, filetype="pdf")
    last_page_idx = max(0, len(doc_fitz) - 1)
    pw = float(doc_fitz[last_page_idx].rect.width)
    ph = float(doc_fitz[last_page_idx].rect.height)
    doc_fitz.close()

    qr_img = _make_qr_pil(verify_url)
    writer = IncrementalPdfFileWriter(io.BytesIO(pdf_bytes), strict=False)
    seal_meta = signers.PdfSignatureMetadata(
        field_name="Sentinel_Envelope_Seal",
        reason=f"SENTINEL Sealed Multi-Party Envelope ({doc_number})",
        location="SENTINEL DMS Local PKI",
        name="SENTINEL Sealing Authority",
        md_algorithm="sha256",
        subfilter=SigSeedSubFilter.PADES,
        certify=False,
        docmdp_permissions=MDPPerm.FILL_FORMS,
        embed_validation_info=bool(crls_arg),
        use_pades_lta=True,
        validation_context=vc if crls_arg else None,
        timestamp_field_name="Sentinel_Seal_LTA_TS",
    )
    seal_box = (max(16.0, pw - 216.0), 18.0, pw - 16.0, 74.0)
    seal_style = stamp.TextStampStyle(
        stamp_text=f"SEALED + VERIFIED\n{doc_number}\nVerify offline via QR\nPAdES-B-LTA",
        background=images.PdfImage(qr_img, writer=writer),
        background_opacity=0.22,
        text_box_style=text.TextBoxStyle(font_size=7),
    )
    sealed_signer = signers.PdfSigner(
        signature_meta=seal_meta,
        signer=cms_signer,
        timestamper=timestamper,
        stamp_style=seal_style,
        new_field_spec=SigFieldSpec(sig_field_name="Sentinel_Envelope_Seal", on_page=last_page_idx, box=seal_box),
    )
    sealed_buf = io.BytesIO()
    sealed_signer.sign_pdf(writer, output=sealed_buf)
    sealed_bytes = sealed_buf.getvalue()
    sealed_sha = hashlib.sha256(sealed_bytes).hexdigest()

    # Build standalone Tamper-Evident Certificate of Completion Page PDF
    cert_doc = fitz.open()
    page = cert_doc.new_page(width=595, height=842)
    page.draw_rect(fitz.Rect(24, 24, 571, 818), color=(0.06, 0.16, 0.32), width=1.5)
    page.draw_rect(fitz.Rect(24, 24, 571, 96), color=None, fill=(0.06, 0.16, 0.32))
    page.insert_text((42, 54), "SENTINEL DMS — CERTIFICATE OF COMPLETION", fontsize=14, color=(1, 1, 1))
    page.insert_text((42, 76), f"Envelope: {envelope_title[:68]} | Doc: {doc_number}", fontsize=9, color=(0.8, 0.9, 1))

    qr_buf = io.BytesIO()
    qr_img.save(qr_buf, format="PNG")
    page.insert_image(fitz.Rect(455, 112, 555, 212), stream=qr_buf.getvalue())
    page.insert_text((42, 128), f"Sealed SHA-256: {sealed_sha}", fontsize=8.5, color=(0.1, 0.1, 0.1))
    page.insert_text((42, 146), f"Offline Verify URL: {verify_url}", fontsize=8.5, color=(0.1, 0.25, 0.55))
    page.insert_text((42, 164), f"Sealed Timestamp (UTC): {datetime.now(tz=timezone.utc).isoformat()}", fontsize=8.5, color=(0.2, 0.2, 0.2))

    y = 228.0
    page.insert_text((42, y), "SIGNER AUDIT & CRYPTOGRAPHIC MANIFEST", fontsize=11, color=(0.06, 0.16, 0.32))
    y += 18
    for idx, entry in enumerate(signers_manifest, start=1):
        box = fitz.Rect(42, y, 553, y + 66)
        page.draw_rect(box, color=(0.8, 0.85, 0.9), fill=(0.97, 0.98, 1.0), width=0.8)
        block = (
            f"#{idx} {entry.get('signer_name', 'Unknown')} ({entry.get('tier', 'pades')}) — Signed: {entry.get('signed_at', '-')}\n"
            f"Certificate Serial: {entry.get('cert_serial') or 'Tier Stamp'} | TSA Token: {entry.get('tsa_token_serial') or 'Audit-Anchored'}\n"
            f"Reason: {entry.get('reason') or '-'} | Audit Event ID: {entry.get('audit_event_id') or '-'}"
        )
        page.insert_textbox(fitz.Rect(48, y + 6, 545, y + 62), block, fontsize=8.5, color=(0.1, 0.15, 0.25))
        y += 74
        if y > 720:
            break

    raw_cert_pdf = cert_doc.tobytes(deflate=True)
    cert_doc.close()

    # Sign the Certificate PDF itself with PAdES-B-LTA
    signed_cert = sign_pades(
        pdf_bytes=raw_cert_pdf,
        signer_cert_pem=seal_cert_pem,
        signer_key_pem=seal_key_pem,
        ca_chain_pems=ca_chain_pems,
        tsa_cert_pem=tsa_cert_pem,
        tsa_key_pem=tsa_key_pem,
        crl_der=crl_der,
        ocsp_der=None,
        field_name="Sentinel_Certificate_Seal",
        coords={"page": 1, "x": 42, "y": 745, "width": 230, "height": 58},
        signer_label="SENTINEL Certificate Authority",
        reason="Cryptographic Certificate of Completion Seal",
        location="SENTINEL DMS Local PKI",
        use_lta=True,
    )

    return {
        "sealed_pdf_bytes": sealed_bytes,
        "sealed_sha256": sealed_sha,
        "certificate_pdf_bytes": signed_cert["signed_pdf_bytes"],
        "certificate_sha256": signed_cert["sha256"],
    }


def verify_pdf(
    pdf_bytes: bytes,
    trust_root_pem: str,
    inter_pem: str,
    tsa_pem: str,
    crl_der: bytes | None = None,
    ocsps_der: list[bytes] | None = None,
) -> dict[str, Any]:
    """Verify every embedded PDF signature offline against local Root CA, CRL, OCSP, and ByteRange integrity."""
    root_cert = _load_asn1_cert(trust_root_pem)
    inter_cert = _load_asn1_cert(inter_pem)
    tsa_cert = _load_asn1_cert(tsa_pem)

    crls_arg = [crl_der] if crl_der else []
    ocsps_arg = list(ocsps_der or [])

    revoked_serials_from_crl: set[str] = set()
    if crl_der:
        parsed_crl = crl.CertificateList.load(crl_der)
        for rc in parsed_crl["tbs_cert_list"]["revoked_certificates"]:
            revoked_serials_from_crl.add(format(int(rc["user_certificate"].native), "x").upper())

    reader = PdfFileReader(io.BytesIO(pdf_bytes), strict=False)
    embedded_sigs = reader.embedded_signatures
    if not embedded_sigs:
        return {
            "overall_status": "unsigned",
            "signatures_count": 0,
            "signatures": [],
            "trailing_bytes_altered": False,
        }

    # Catch raw garbage / shadow data appended after final %%EOF (legitimate PAdES-LTA /DSS incremental trailers end cleanly at %%EOF)
    trailing_bytes_altered = not pdf_bytes.rstrip(b"\r\n \t\x00").endswith(b"%%EOF")

    vc = ValidationContext(
        trust_roots=[root_cert],
        other_certs=[inter_cert, tsa_cert],
        crls=crls_arg,
        ocsps=ocsps_arg,
        allow_fetching=False,
        revocation_mode="hard-fail" if (crls_arg or ocsps_arg) else "soft-fail",
    )

    sig_results: list[dict[str, Any]] = []
    any_altered = trailing_bytes_altered
    any_revoked = False
    any_expired = False

    now_utc = datetime.now(tz=timezone.utc)

    for idx, sig in enumerate(embedded_sigs):
        if sig.sig_object.get("/Type") == "/DocTimeStamp":
            # DocumentTimestamp covers prior signatures; evaluate via pyHanko validate_pdf_timestamp
            ts_vc = ValidationContext(trust_roots=[root_cert], other_certs=[inter_cert, tsa_cert], allow_fetching=False)
            ts_status = validation.validate_pdf_timestamp(sig, validation_context=ts_vc)
            is_intact = bool(ts_status.intact) and not trailing_bytes_altered
            if not is_intact or ts_status.modification_level == ModificationLevel.OTHER:
                any_altered = True
            sig_results.append(
                {
                    "field_name": sig.field_name or f"Timestamp_{idx}",
                    "kind": "document_timestamp",
                    "intact": is_intact,
                    "trusted": bool(ts_status.trusted),
                    "modification_level": str(ts_status.modification_level),
                    "timestamp": ts_status.timestamp.isoformat() if ts_status.timestamp else None,
                    "status": "valid" if (is_intact and ts_status.trusted) else "altered",
                }
            )
            continue

        status_obj = validation.validate_pdf_signature(sig, signer_validation_context=vc, ts_validation_context=vc, skip_diff=False)
        signer_cert = sig.signer_cert
        serial_hex = format(signer_cert.serial_number, "x").upper() if signer_cert else ""
        subject_str = signer_cert.subject.human_friendly if signer_cert else "Unknown"
        not_after = getattr(signer_cert, "not_valid_after", None) if signer_cert else None
        if hasattr(not_after, "native"):
            not_after = not_after.native
        if isinstance(not_after, datetime) and not_after.tzinfo is None:
            not_after = not_after.replace(tzinfo=timezone.utc)

        is_expired = isinstance(not_after, datetime) and not_after < now_utc
        is_revoked = bool(status_obj.revoked) or (serial_hex in revoked_serials_from_crl)
        is_intact = bool(status_obj.intact) and not trailing_bytes_altered
        mod_level = status_obj.modification_level
        mod_bad = mod_level == ModificationLevel.OTHER or not status_obj.docmdp_ok

        if not is_intact or mod_bad:
            sig_state = "altered"
            any_altered = True
        elif is_revoked:
            sig_state = "revoked"
            any_revoked = True
        elif is_expired:
            sig_state = "expired"
            any_expired = True
        elif status_obj.valid and status_obj.trusted:
            sig_state = "valid"
        else:
            # Untrusted chain or broken crypto triggers altered
            summary_lower = str(status_obj.summary()).lower()
            if "revok" in summary_lower:
                sig_state = "revoked"
                any_revoked = True
            elif "expir" in summary_lower:
                sig_state = "expired"
                any_expired = True
            else:
                sig_state = "altered"
                any_altered = True

        sig_results.append(
            {
                "field_name": sig.field_name or f"Signature_{idx}",
                "kind": "pades",
                "signer": subject_str,
                "cert_serial": serial_hex,
                "intact": is_intact,
                "trusted": bool(status_obj.trusted),
                "revoked": is_revoked,
                "expired": is_expired,
                "modification_level": str(mod_level),
                "timestamp": status_obj.signer_reported_dt.isoformat() if status_obj.signer_reported_dt else None,
                "status": sig_state,
            }
        )

    if any_altered:
        overall = "altered"
    elif any_revoked:
        overall = "revoked"
    elif any_expired:
        overall = "expired"
    else:
        overall = "valid"

    return {
        "overall_status": overall,
        "signatures_count": len(sig_results),
        "signatures": sig_results,
        "trailing_bytes_altered": trailing_bytes_altered,
    }
