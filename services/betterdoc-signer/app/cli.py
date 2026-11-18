"""CLI entrypoint for local pyHanko / X.509 execution (reads JSON request file, writes JSON response)."""

from __future__ import annotations

import base64
import json
import sys
from pathlib import Path

from app import pki_engine, print_scan_engine, redaction_engine, signing_engine


def main() -> int:
    if len(sys.argv) < 3:
        sys.stderr.write("Usage: python -m app.cli <action> <payload.json>\n")
        return 2

    action = sys.argv[1]
    payload = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))

    if action == "bootstrap_pki":
        out = pki_engine.bootstrap_tenant_pki(
            tenant_name=payload["tenant_name"],
            tenant_slug=payload["tenant_slug"],
            ocsp_base_url=payload["ocsp_base_url"],
            crl_url=payload["crl_url"],
        )
        print(json.dumps(out))
        return 0

    if action == "issue_user_cert":
        out = pki_engine.issue_user_cert(
            ca_cert_pem=payload["ca_cert_pem"],
            ca_key_pem=payload["ca_key_pem"],
            common_name=payload["common_name"],
            email=payload["email"],
            org_name=payload["org_name"],
            org_unit=payload["org_unit"],
            ocsp_url=payload["ocsp_url"],
            crl_url=payload["crl_url"],
            valid_days=int(payload.get("valid_days", 365)),
            not_before_offset_days=int(payload.get("not_before_offset_days", 0)),
            csr_pem=payload.get("csr_pem"),
        )
        print(json.dumps(out))
        return 0

    if action == "build_crl":
        der = pki_engine.build_crl(
            ca_cert_pem=payload["ca_cert_pem"],
            ca_key_pem=payload["ca_key_pem"],
            crl_number=int(payload["crl_number"]),
            revoked_entries=payload["revoked_entries"],
        )
        print(json.dumps({"crl_der_b64": base64.b64encode(der).decode("ascii")}))
        return 0

    if action == "build_ocsp":
        der = pki_engine.build_ocsp_response(
            target_cert_pem=payload["target_cert_pem"],
            issuer_cert_pem=payload["issuer_cert_pem"],
            responder_cert_pem=payload["responder_cert_pem"],
            responder_key_pem=payload["responder_key_pem"],
            revoked_at=payload.get("revoked_at"),
            revocation_reason=payload.get("revocation_reason"),
        )
        print(json.dumps({"ocsp_der_b64": base64.b64encode(der).decode("ascii")}))
        return 0

    if action == "stamp_tier1":
        pdf_bytes = Path(payload["input_pdf_path"]).read_bytes()
        stamped = signing_engine.apply_tier1_stamp(
            pdf_bytes=pdf_bytes,
            coords=payload["coords"],
            signer_name=payload["signer_name"],
            rank_title=payload["rank_title"],
            reason=payload["reason"],
            typed_name=payload.get("typed_name"),
            drawn_svg_path=payload.get("drawn_svg_path"),
        )
        Path(payload["output_pdf_path"]).write_bytes(stamped)
        print(json.dumps({"ok": True, "size": len(stamped)}))
        return 0

    if action == "sign_pades":
        pdf_bytes = Path(payload["input_pdf_path"]).read_bytes()
        res = signing_engine.sign_pades(
            pdf_bytes=pdf_bytes,
            signer_cert_pem=payload["signer_cert_pem"],
            signer_key_pem=payload["signer_key_pem"],
            ca_chain_pems=payload["ca_chain_pems"],
            tsa_cert_pem=payload["tsa_cert_pem"],
            tsa_key_pem=payload["tsa_key_pem"],
            crl_der=base64.b64decode(payload["crl_der_b64"]) if payload.get("crl_der_b64") else None,
            ocsp_der=base64.b64decode(payload["ocsp_der_b64"]) if payload.get("ocsp_der_b64") else None,
            field_name=payload["field_name"],
            coords=payload["coords"],
            signer_label=payload["signer_label"],
            reason=payload["reason"],
            location=payload["location"],
            use_lta=bool(payload.get("use_lta", False)),
        )
        signed_bytes = res.pop("signed_pdf_bytes")
        Path(payload["output_pdf_path"]).write_bytes(signed_bytes)
        print(json.dumps(res))
        return 0

    if action == "prepare_external":
        pdf_bytes = Path(payload["input_pdf_path"]).read_bytes()
        state = signing_engine.prepare_external_pades(
            pdf_bytes=pdf_bytes,
            signer_cert_pem=payload["signer_cert_pem"],
            ca_chain_pems=payload["ca_chain_pems"],
            field_name=payload["field_name"],
            coords=payload["coords"],
            signer_label=payload["signer_label"],
            reason=payload["reason"],
            location=payload["location"],
        )
        print(json.dumps(state))
        return 0

    if action == "finalize_external":
        res = signing_engine.finalize_external_pades(
            state=payload["state"],
            signature_b64=payload["signature_b64"],
            tsa_cert_pem=payload["tsa_cert_pem"],
            tsa_key_pem=payload["tsa_key_pem"],
            crl_der=base64.b64decode(payload["crl_der_b64"]) if payload.get("crl_der_b64") else None,
            ocsp_der=base64.b64decode(payload["ocsp_der_b64"]) if payload.get("ocsp_der_b64") else None,
        )
        signed_bytes = res.pop("signed_pdf_bytes")
        Path(payload["output_pdf_path"]).write_bytes(signed_bytes)
        print(json.dumps(res))
        return 0

    if action == "seal_envelope":
        pdf_bytes = Path(payload["input_pdf_path"]).read_bytes()
        res = signing_engine.seal_envelope_pdf_and_certificate(
            pdf_bytes=pdf_bytes,
            seal_cert_pem=payload["seal_cert_pem"],
            seal_key_pem=payload["seal_key_pem"],
            ca_chain_pems=payload["ca_chain_pems"],
            tsa_cert_pem=payload["tsa_cert_pem"],
            tsa_key_pem=payload["tsa_key_pem"],
            crl_der=base64.b64decode(payload["crl_der_b64"]) if payload.get("crl_der_b64") else None,
            verify_url=payload["verify_url"],
            envelope_title=payload["envelope_title"],
            doc_number=payload["doc_number"],
            signers_manifest=payload["signers_manifest"],
        )
        sealed_bytes = res.pop("sealed_pdf_bytes")
        cert_bytes = res.pop("certificate_pdf_bytes")
        Path(payload["sealed_pdf_path"]).write_bytes(sealed_bytes)
        Path(payload["certificate_pdf_path"]).write_bytes(cert_bytes)
        print(json.dumps(res))
        return 0

    if action == "verify_pdf":
        pdf_bytes = Path(payload["input_pdf_path"]).read_bytes()
        ocsps = [base64.b64decode(b) for b in payload.get("ocsps_der_b64", []) if b]
        res = signing_engine.verify_pdf(
            pdf_bytes=pdf_bytes,
            trust_root_pem=payload["trust_root_pem"],
            inter_pem=payload["inter_pem"],
            tsa_pem=payload["tsa_pem"],
            crl_der=base64.b64decode(payload["crl_der_b64"]) if payload.get("crl_der_b64") else None,
            ocsps_der=ocsps,
        )
        print(json.dumps(res))
        return 0

    if action == "sign_raw_digest":
        sig = pki_engine.sign_raw_pkcs1v15_sha256(
            private_key_pem=payload["private_key_pem"],
            data_bytes=base64.b64decode(payload["data_b64"]),
        )
        print(json.dumps({"signature_b64": base64.b64encode(sig).decode("ascii")}))
        return 0

    if action == "redact_pdf":
        pdf_bytes = Path(payload["input_pdf_path"]).read_bytes()
        res = redaction_engine.redact_pdf(
            pdf_bytes=pdf_bytes,
            boxes=payload.get("boxes", []),
            scrub_metadata=bool(payload.get("scrub_metadata", True)),
        )
        redacted_bytes = res.pop("redacted_pdf_bytes")
        Path(payload["output_pdf_path"]).write_bytes(redacted_bytes)
        print(json.dumps(res))
        return 0

    if action == "render_html_pdf":
        res = redaction_engine.render_html_to_pdf(
            html_body=payload["html_body"],
            title=payload["title"],
            classification_banner=payload.get("classification_banner", ""),
            doc_number=payload.get("doc_number", ""),
        )
        pdf_bytes = res.pop("pdf_bytes")
        Path(payload["output_pdf_path"]).write_bytes(pdf_bytes)
        print(json.dumps(res))
        return 0

    if action == "watermark_pdf":
        pdf_bytes = Path(payload["input_pdf_path"]).read_bytes()
        res = print_scan_engine.watermark_pdf(
            pdf_bytes=pdf_bytes,
            watermark_text=payload["watermark_text"],
            classification_banner=payload.get("classification_banner", ""),
        )
        out_bytes = res.pop("watermarked_pdf_bytes")
        Path(payload["output_pdf_path"]).write_bytes(out_bytes)
        print(json.dumps(res))
        return 0

    if action == "generate_separator_pdf":
        pdf_bytes = print_scan_engine.generate_separator_pdf(
            doc_type=payload.get("doc_type", "general"),
            title=payload.get("title", "Scanned Record"),
            cabinet_code=payload.get("cabinet_code", ""),
            classification_name=payload.get("classification_name", "Internal"),
        )
        Path(payload["output_pdf_path"]).write_bytes(pdf_bytes)
        print(json.dumps({"ok": True, "size": len(pdf_bytes)}))
        return 0

    if action == "split_by_separators":
        pdf_bytes = Path(payload["input_pdf_path"]).read_bytes()
        res = print_scan_engine.split_pdf_by_separators(pdf_bytes)
        print(json.dumps(res))
        return 0

    if action == "deskew_images_pdf":
        res = print_scan_engine.deskew_and_crop_images_to_pdf(payload.get("image_paths", []))
        print(json.dumps(res))
        return 0

    sys.stderr.write(f"Unknown action: {action}\n")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
