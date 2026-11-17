"""FastAPI HTTP Microservice for pyHanko PAdES Signing, PKI Operations & Verification."""

from __future__ import annotations

import base64
from typing import Any

from fastapi import FastAPI
from pydantic import BaseModel

from app import pki_engine, redaction_engine, signing_engine

app = FastAPI(title="SENTINEL PAdES & PKCS#11 Signer")


class SignPadesBody(BaseModel):
    pdf_b64: str
    signer_cert_pem: str
    signer_key_pem: str
    ca_chain_pems: list[str]
    tsa_cert_pem: str
    tsa_key_pem: str
    crl_der_b64: str | None = None
    ocsp_der_b64: str | None = None
    field_name: str
    coords: dict[str, Any]
    signer_label: str
    reason: str
    location: str
    use_lta: bool = False


class VerifyPdfBody(BaseModel):
    pdf_b64: str
    trust_root_pem: str
    inter_pem: str
    tsa_pem: str
    crl_der_b64: str | None = None
    ocsps_der_b64: list[str] = []


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok", "service": "sentinel-signer"}


@app.post("/sign/pades")
def sign_pades_endpoint(body: SignPadesBody) -> dict[str, Any]:
    res = signing_engine.sign_pades(
        pdf_bytes=base64.b64decode(body.pdf_b64),
        signer_cert_pem=body.signer_cert_pem,
        signer_key_pem=body.signer_key_pem,
        ca_chain_pems=body.ca_chain_pems,
        tsa_cert_pem=body.tsa_cert_pem,
        tsa_key_pem=body.tsa_key_pem,
        crl_der=base64.b64decode(body.crl_der_b64) if body.crl_der_b64 else None,
        ocsp_der=base64.b64decode(body.ocsp_der_b64) if body.ocsp_der_b64 else None,
        field_name=body.field_name,
        coords=body.coords,
        signer_label=body.signer_label,
        reason=body.reason,
        location=body.location,
        use_lta=body.use_lta,
    )
    res["signed_pdf_b64"] = base64.b64encode(res.pop("signed_pdf_bytes")).decode("ascii")
    return res


@app.post("/verify/pdf")
def verify_pdf_endpoint(body: VerifyPdfBody) -> dict[str, Any]:
    return signing_engine.verify_pdf(
        pdf_bytes=base64.b64decode(body.pdf_b64),
        trust_root_pem=body.trust_root_pem,
        inter_pem=body.inter_pem,
        tsa_pem=body.tsa_pem,
        crl_der=base64.b64decode(body.crl_der_b64) if body.crl_der_b64 else None,
        ocsps_der=[base64.b64decode(b) for b in body.ocsps_der_b64 if b],
    )


class RedactPdfBody(BaseModel):
    pdf_b64: str
    boxes: list[dict[str, Any]]
    scrub_metadata: bool = True


class RenderHtmlPdfBody(BaseModel):
    html_body: str
    title: str
    classification_banner: str = ""
    doc_number: str = ""


@app.post("/redact/pdf")
def redact_pdf_endpoint(body: RedactPdfBody) -> dict[str, Any]:
    res = redaction_engine.redact_pdf(
        pdf_bytes=base64.b64decode(body.pdf_b64),
        boxes=body.boxes,
        scrub_metadata=body.scrub_metadata,
    )
    res["redacted_pdf_b64"] = base64.b64encode(res.pop("redacted_pdf_bytes")).decode("ascii")
    return res


@app.post("/render/html-pdf")
def render_html_pdf_endpoint(body: RenderHtmlPdfBody) -> dict[str, Any]:
    res = redaction_engine.render_html_to_pdf(
        html_body=body.html_body,
        title=body.title,
        classification_banner=body.classification_banner,
        doc_number=body.doc_number,
    )
    res["pdf_b64"] = base64.b64encode(res.pop("pdf_bytes")).decode("ascii")
    return res
