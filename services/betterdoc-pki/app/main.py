"""BetterDoc Local PKI Microservice: RFC 3161 Time-Stamp Authority, CRL & OCSP Responder."""

from __future__ import annotations

import os
from datetime import datetime, timezone
from pathlib import Path

from asn1crypto import cms, core, ocsp, tsp, x509
from asn1crypto.algos import DigestAlgorithm, SignedDigestAlgorithm
from cryptography import x509 as cx509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.asymmetric.rsa import RSAPrivateKey
from fastapi import FastAPI, HTTPException, Request, Response
from pydantic import BaseModel

app = FastAPI(title="BetterDoc Internal PKI (TSA / OCSP / CRL)")

PKI_STATE_DIR = Path(os.getenv("BETTERDOC_PKI_STATE_DIR", "/var/lib/betterdoc-pki"))


class OcspCheckRequest(BaseModel):
    serial_hex: str
    revoked_serials: dict[str, dict[str, str]] = {}
    responder_cert_pem: str
    responder_key_pem: str
    issuer_cert_pem: str


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok", "service": "betterdoc-pki"}


@app.post("/tsa")
async def rfc3161_timestamp(request: Request) -> Response:
    """Handle RFC 3161 ASN.1 DER TimeStampReq offline using local TSA HSM key/cert."""
    raw = await request.body()
    if not raw:
        raise HTTPException(status_code=400, detail="Empty RFC 3161 TimeStampReq")

    cert_path = Path(os.getenv("BETTERDOC_TSA_CERT", str(PKI_STATE_DIR / "tsa.crt")))
    key_path = Path(os.getenv("BETTERDOC_TSA_KEY", str(PKI_STATE_DIR / "tsa.key")))
    if not cert_path.exists() or not key_path.exists():
        raise HTTPException(status_code=503, detail="TSA keypair not configured on PKI node")

    tsa_cert = x509.Certificate.load(
        cx509.load_pem_x509_certificate(cert_path.read_bytes()).public_bytes(serialization.Encoding.DER)
    )
    priv_obj = serialization.load_pem_private_key(key_path.read_bytes(), password=None)
    if not isinstance(priv_obj, RSAPrivateKey):
        raise HTTPException(status_code=500, detail="TSA private key must be RSA")

    ts_req = tsp.TimeStampReq.load(raw)
    msg_imprint = ts_req["message_imprint"]
    md_algo = msg_imprint["hash_algorithm"]["algorithm"].native
    now_utc = datetime.now(tz=timezone.utc)

    tst_info_args: dict[str, object] = {
        "version": "v1",
        "policy": tsp.ObjectIdentifier("1.3.6.1.4.1.59999.1.1"),
        "message_imprint": msg_imprint,
        "serial_number": int.from_bytes(os.urandom(12), "big"),
        "gen_time": now_utc,
        "tsa": x509.GeneralName(name="directory_name", value=tsa_cert.subject),
    }
    if ts_req["nonce"].native is not None:
        tst_info_args["nonce"] = ts_req["nonce"]

    tst_info_der = tsp.TSTInfo(tst_info_args).dump()
    hash_cls = hashes.SHA256() if md_algo == "sha256" else hashes.SHA384()
    digest = hashes.Hash(hash_cls)
    digest.update(tst_info_der)
    msg_digest = digest.finalize()

    signed_attrs = cms.CMSAttributes(
        [
            cms.CMSAttribute({"type": cms.CMSAttributeType("content_type"), "values": ["tst_info"]}),
            cms.CMSAttribute(
                {"type": cms.CMSAttributeType("signing_time"), "values": [cms.Time({"utc_time": core.UTCTime(now_utc)})]}
            ),
            cms.CMSAttribute({"type": cms.CMSAttributeType("message_digest"), "values": [msg_digest]}),
        ]
    )
    sig_bytes = priv_obj.sign(signed_attrs.dump(), padding.PKCS1v15(), hash_cls)

    signer_info = cms.SignerInfo(
        {
            "version": "v1",
            "sid": cms.SignerIdentifier(
                {
                    "issuer_and_serial_number": cms.IssuerAndSerialNumber(
                        {"issuer": tsa_cert.issuer, "serial_number": tsa_cert.serial_number}
                    )
                }
            ),
            "digest_algorithm": DigestAlgorithm({"algorithm": md_algo}),
            "signature_algorithm": SignedDigestAlgorithm({"algorithm": "rsassa_pkcs1v15"}),
            "signed_attrs": signed_attrs,
            "signature": sig_bytes,
        }
    )

    signed_data = cms.SignedData(
        {
            "version": "v3",
            "digest_algorithms": cms.DigestAlgorithms((DigestAlgorithm({"algorithm": md_algo}),)),
            "encap_content_info": cms.EncapsulatedContentInfo(
                {"content_type": cms.ContentType("tst_info"), "content": cms.ParsableOctetString(tst_info_der)}
            ),
            "certificates": {tsa_cert},
            "signer_infos": [signer_info],
        }
    )

    ts_resp = tsp.TimeStampResp(
        {
            "status": tsp.PKIStatusInfo({"status": tsp.PKIStatus("granted")}),
            "time_stamp_token": cms.ContentInfo({"content_type": cms.ContentType("signed_data"), "content": signed_data}),
        }
    )
    return Response(content=ts_resp.dump(), media_type="application/timestamp-reply")
