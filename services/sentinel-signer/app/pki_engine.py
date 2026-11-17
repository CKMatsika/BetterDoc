"""X.509 Internal CA, CRL, and OCSP cryptographic primitives for SENTINEL DMS."""

from __future__ import annotations

import hashlib
import os
from datetime import datetime, timedelta, timezone
from typing import Any

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.asymmetric.rsa import RSAPrivateKey
from cryptography.x509 import ocsp
from cryptography.x509.oid import AuthorityInformationAccessOID, ExtendedKeyUsageOID, NameOID


def _pem_cert_info(cert: x509.Certificate) -> dict[str, Any]:
    cert_pem = cert.public_bytes(serialization.Encoding.PEM).decode("ascii")
    cert_der = cert.public_bytes(serialization.Encoding.DER)
    return {
        "serial_number": format(cert.serial_number, "x").upper(),
        "subject_dn": cert.subject.rfc4514_string(),
        "issuer_dn": cert.issuer.rfc4514_string(),
        "certificate_pem": cert_pem,
        "fingerprint_sha256": hashlib.sha256(cert_der).hexdigest(),
        "valid_from": cert.not_valid_before_utc.isoformat(),
        "valid_to": cert.not_valid_after_utc.isoformat(),
    }


def _pem_key(key: RSAPrivateKey) -> str:
    return key.private_bytes(
        encoding=serialization.Encoding.PEM,
        format=serialization.PrivateFormat.PKCS8,
        encryption_algorithm=serialization.NoEncryption(),
    ).decode("ascii")


def bootstrap_tenant_pki(tenant_name: str, tenant_slug: str, ocsp_base_url: str, crl_url: str) -> dict[str, Any]:
    """Generate offline Root CA, Intermediate CA, Local RFC 3161 TSA, and OCSP Responder certificates."""
    now = datetime.now(tz=timezone.utc) - timedelta(minutes=5)

    # 1. Offline Root CA (RSA 4096)
    root_key = rsa.generate_private_key(public_exponent=65537, key_size=4096)
    root_subject = x509.Name(
        [
            x509.NameAttribute(NameOID.ORGANIZATION_NAME, tenant_name[:64]),
            x509.NameAttribute(NameOID.ORGANIZATIONAL_UNIT_NAME, "SENTINEL Offline Trust Anchor"),
            x509.NameAttribute(NameOID.COMMON_NAME, f"{tenant_slug.upper()} Offline Root CA"),
        ]
    )
    root_ski = x509.SubjectKeyIdentifier.from_public_key(root_key.public_key())
    root_cert = (
        x509.CertificateBuilder()
        .subject_name(root_subject)
        .issuer_name(root_subject)
        .public_key(root_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now)
        .not_valid_after(now + timedelta(days=3650))
        .add_extension(x509.BasicConstraints(ca=True, path_length=1), critical=True)
        .add_extension(
            x509.KeyUsage(
                digital_signature=True,
                content_commitment=False,
                key_encipherment=False,
                data_encipherment=False,
                key_agreement=False,
                key_cert_sign=True,
                crl_sign=True,
                encipher_only=False,
                decipher_only=False,
            ),
            critical=True,
        )
        .add_extension(root_ski, critical=False)
        .add_extension(x509.AuthorityKeyIdentifier.from_issuer_subject_key_identifier(root_ski), critical=False)
        .sign(root_key, hashes.SHA256())
    )

    # 2. Online Intermediate CA (RSA 3072) signed by Root CA
    inter_key = rsa.generate_private_key(public_exponent=65537, key_size=3072)
    inter_subject = x509.Name(
        [
            x509.NameAttribute(NameOID.ORGANIZATION_NAME, tenant_name[:64]),
            x509.NameAttribute(NameOID.ORGANIZATIONAL_UNIT_NAME, "SENTINEL Issuing Authority"),
            x509.NameAttribute(NameOID.COMMON_NAME, f"{tenant_slug.upper()} Issuing Intermediate CA"),
        ]
    )
    inter_ski = x509.SubjectKeyIdentifier.from_public_key(inter_key.public_key())
    inter_cert = (
        x509.CertificateBuilder()
        .subject_name(inter_subject)
        .issuer_name(root_subject)
        .public_key(inter_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now)
        .not_valid_after(now + timedelta(days=1825))
        .add_extension(x509.BasicConstraints(ca=True, path_length=0), critical=True)
        .add_extension(
            x509.KeyUsage(
                digital_signature=True,
                content_commitment=False,
                key_encipherment=False,
                data_encipherment=False,
                key_agreement=False,
                key_cert_sign=True,
                crl_sign=True,
                encipher_only=False,
                decipher_only=False,
            ),
            critical=True,
        )
        .add_extension(inter_ski, critical=False)
        .add_extension(x509.AuthorityKeyIdentifier.from_issuer_subject_key_identifier(root_ski), critical=False)
        .sign(root_key, hashes.SHA256())
    )

    # 3. Local RFC 3161 Time-Stamp Authority Cert signed by Intermediate CA
    tsa_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    tsa_subject = x509.Name(
        [
            x509.NameAttribute(NameOID.ORGANIZATION_NAME, tenant_name[:64]),
            x509.NameAttribute(NameOID.ORGANIZATIONAL_UNIT_NAME, "Local RFC 3161 TSA"),
            x509.NameAttribute(NameOID.COMMON_NAME, f"{tenant_slug.upper()} Local Time-Stamping Authority"),
        ]
    )
    tsa_ski = x509.SubjectKeyIdentifier.from_public_key(tsa_key.public_key())
    tsa_cert = (
        x509.CertificateBuilder()
        .subject_name(tsa_subject)
        .issuer_name(inter_subject)
        .public_key(tsa_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now)
        .not_valid_after(now + timedelta(days=1095))
        .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
        .add_extension(
            x509.KeyUsage(
                digital_signature=True,
                content_commitment=False,
                key_encipherment=False,
                data_encipherment=False,
                key_agreement=False,
                key_cert_sign=False,
                crl_sign=False,
                encipher_only=False,
                decipher_only=False,
            ),
            critical=True,
        )
        .add_extension(x509.ExtendedKeyUsage([ExtendedKeyUsageOID.TIME_STAMPING]), critical=True)
        .add_extension(tsa_ski, critical=False)
        .add_extension(x509.AuthorityKeyIdentifier.from_issuer_subject_key_identifier(inter_ski), critical=False)
        .sign(inter_key, hashes.SHA256())
    )

    # 4. Local OCSP Responder Cert signed by Intermediate CA
    ocsp_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    ocsp_subject = x509.Name(
        [
            x509.NameAttribute(NameOID.ORGANIZATION_NAME, tenant_name[:64]),
            x509.NameAttribute(NameOID.ORGANIZATIONAL_UNIT_NAME, "Local OCSP Responder"),
            x509.NameAttribute(NameOID.COMMON_NAME, f"{tenant_slug.upper()} OCSP Signer"),
        ]
    )
    ocsp_ski = x509.SubjectKeyIdentifier.from_public_key(ocsp_key.public_key())
    ocsp_cert = (
        x509.CertificateBuilder()
        .subject_name(ocsp_subject)
        .issuer_name(inter_subject)
        .public_key(ocsp_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now)
        .not_valid_after(now + timedelta(days=730))
        .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
        .add_extension(
            x509.KeyUsage(
                digital_signature=True,
                content_commitment=False,
                key_encipherment=False,
                data_encipherment=False,
                key_agreement=False,
                key_cert_sign=False,
                crl_sign=False,
                encipher_only=False,
                decipher_only=False,
            ),
            critical=True,
        )
        .add_extension(x509.ExtendedKeyUsage([ExtendedKeyUsageOID.OCSP_SIGNING]), critical=True)
        .add_extension(ocsp_ski, critical=False)
        .add_extension(x509.AuthorityKeyIdentifier.from_issuer_subject_key_identifier(inter_ski), critical=False)
        .sign(inter_key, hashes.SHA256())
    )

    # 5. Document Sealing Authority Leaf Cert (nonRepudiation) signed by Intermediate CA
    seal_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    seal_subject = x509.Name(
        [
            x509.NameAttribute(NameOID.ORGANIZATION_NAME, tenant_name[:64]),
            x509.NameAttribute(NameOID.ORGANIZATIONAL_UNIT_NAME, "Document Sealing Authority"),
            x509.NameAttribute(NameOID.COMMON_NAME, f"{tenant_slug.upper()} Envelope Sealing Authority"),
        ]
    )
    seal_ski = x509.SubjectKeyIdentifier.from_public_key(seal_key.public_key())
    seal_cert = (
        x509.CertificateBuilder()
        .subject_name(seal_subject)
        .issuer_name(inter_subject)
        .public_key(seal_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now)
        .not_valid_after(now + timedelta(days=1095))
        .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
        .add_extension(
            x509.KeyUsage(
                digital_signature=True,
                content_commitment=True,
                key_encipherment=False,
                data_encipherment=False,
                key_agreement=False,
                key_cert_sign=False,
                crl_sign=False,
                encipher_only=False,
                decipher_only=False,
            ),
            critical=True,
        )
        .add_extension(
            x509.ExtendedKeyUsage([x509.ObjectIdentifier("1.2.840.113583.1.1.5"), ExtendedKeyUsageOID.EMAIL_PROTECTION]),
            critical=False,
        )
        .add_extension(seal_ski, critical=False)
        .add_extension(x509.AuthorityKeyIdentifier.from_issuer_subject_key_identifier(inter_ski), critical=False)
        .sign(inter_key, hashes.SHA256())
    )

    return {
        "root": {**_pem_cert_info(root_cert), "private_key_pem": _pem_key(root_key)},
        "intermediate": {**_pem_cert_info(inter_cert), "private_key_pem": _pem_key(inter_key)},
        "tsa": {**_pem_cert_info(tsa_cert), "private_key_pem": _pem_key(tsa_key)},
        "ocsp": {**_pem_cert_info(ocsp_cert), "private_key_pem": _pem_key(ocsp_key)},
        "seal": {**_pem_cert_info(seal_cert), "private_key_pem": _pem_key(seal_key)},
        "ocsp_base_url": ocsp_base_url,
        "crl_url": crl_url,
    }


def issue_user_cert(
    ca_cert_pem: str,
    ca_key_pem: str,
    common_name: str,
    email: str,
    org_name: str,
    org_unit: str,
    ocsp_url: str,
    crl_url: str,
    valid_days: int = 365,
    not_before_offset_days: int = 0,
    csr_pem: str | None = None,
) -> dict[str, Any]:
    """Issue an X.509 PAdES user signing certificate (HSM-generated keypair or external Smartcard CSR)."""
    ca_cert = x509.load_pem_x509_certificate(ca_cert_pem.encode("ascii"))
    ca_key = serialization.load_pem_private_key(ca_key_pem.encode("ascii"), password=None)
    if not isinstance(ca_key, RSAPrivateKey):
        raise ValueError("CA key must be RSA")

    now = datetime.now(tz=timezone.utc) + timedelta(days=not_before_offset_days)
    # Allow slight negative clock skew for active certs unless caller deliberately creates a backdated expired test cert
    not_before = now - timedelta(minutes=5) if valid_days > 0 else now + timedelta(days=valid_days - 30)
    not_after = now + timedelta(days=valid_days)

    if csr_pem:
        csr = x509.load_pem_x509_csr(csr_pem.encode("ascii"))
        pub_key = csr.public_key()
        priv_pem: str | None = None
    else:
        user_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        pub_key = user_key.public_key()
        priv_pem = _pem_key(user_key)

    subject = x509.Name(
        [
            x509.NameAttribute(NameOID.ORGANIZATION_NAME, org_name[:64]),
            x509.NameAttribute(NameOID.ORGANIZATIONAL_UNIT_NAME, (org_unit or "Operations")[:64]),
            x509.NameAttribute(NameOID.COMMON_NAME, common_name[:64]),
            x509.NameAttribute(NameOID.EMAIL_ADDRESS, email[:64]),
        ]
    )

    ca_ski = ca_cert.extensions.get_extension_for_class(x509.SubjectKeyIdentifier).value
    user_ski = x509.SubjectKeyIdentifier.from_public_key(pub_key)

    builder = (
        x509.CertificateBuilder()
        .subject_name(subject)
        .issuer_name(ca_cert.subject)
        .public_key(pub_key)
        .serial_number(x509.random_serial_number())
        .not_valid_before(not_before)
        .not_valid_after(not_after)
        .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
        .add_extension(
            x509.KeyUsage(
                digital_signature=True,
                content_commitment=True,  # nonRepudiation
                key_encipherment=False,
                data_encipherment=False,
                key_agreement=False,
                key_cert_sign=False,
                crl_sign=False,
                encipher_only=False,
                decipher_only=False,
            ),
            critical=True,
        )
        .add_extension(
            x509.ExtendedKeyUsage(
                [
                    ExtendedKeyUsageOID.EMAIL_PROTECTION,
                    ExtendedKeyUsageOID.CODE_SIGNING,
                    x509.ObjectIdentifier("1.2.840.113583.1.1.5"),  # Adobe Document Signing EKU
                ]
            ),
            critical=False,
        )
        .add_extension(x509.SubjectAlternativeName([x509.RFC822Name(email)]), critical=False)
        .add_extension(
            x509.CRLDistributionPoints(
                [x509.DistributionPoint(full_name=[x509.UniformResourceIdentifier(crl_url)], relative_name=None, reasons=None, crl_issuer=None)]
            ),
            critical=False,
        )
        .add_extension(
            x509.AuthorityInformationAccess(
                [x509.AccessDescription(AuthorityInformationAccessOID.OCSP, x509.UniformResourceIdentifier(ocsp_url))]
            ),
            critical=False,
        )
        .add_extension(user_ski, critical=False)
        .add_extension(x509.AuthorityKeyIdentifier.from_issuer_subject_key_identifier(ca_ski), critical=False)
    )

    cert = builder.sign(ca_key, hashes.SHA256())
    result = _pem_cert_info(cert)
    if priv_pem is not None:
        result["private_key_pem"] = priv_pem
    return result


def build_crl(
    ca_cert_pem: str,
    ca_key_pem: str,
    crl_number: int,
    revoked_entries: list[dict[str, Any]],
) -> bytes:
    """Build a DER-encoded X.509 v2 Certificate Revocation List."""
    ca_cert = x509.load_pem_x509_certificate(ca_cert_pem.encode("ascii"))
    ca_key = serialization.load_pem_private_key(ca_key_pem.encode("ascii"), password=None)
    if not isinstance(ca_key, RSAPrivateKey):
        raise ValueError("CA key must be RSA")

    now = datetime.now(tz=timezone.utc)
    ca_ski = ca_cert.extensions.get_extension_for_class(x509.SubjectKeyIdentifier).value

    builder = (
        x509.CertificateRevocationListBuilder()
        .issuer_name(ca_cert.subject)
        .last_update(now - timedelta(minutes=1))
        .next_update(now + timedelta(days=7))
        .add_extension(x509.CRLNumber(crl_number), critical=False)
        .add_extension(x509.AuthorityKeyIdentifier.from_issuer_subject_key_identifier(ca_ski), critical=False)
    )

    reason_map = {
        "key_compromise": x509.ReasonFlags.key_compromise,
        "ca_compromise": x509.ReasonFlags.ca_compromise,
        "affiliation_changed": x509.ReasonFlags.affiliation_changed,
        "superseded": x509.ReasonFlags.superseded,
        "cessation_of_operation": x509.ReasonFlags.cessation_of_operation,
        "privilege_withdrawn": x509.ReasonFlags.privilege_withdrawn,
    }

    for entry in revoked_entries:
        serial_int = int(str(entry["serial_number"]), 16)
        rev_at = datetime.fromisoformat(str(entry["revoked_at"]).replace("Z", "+00:00"))
        if rev_at.tzinfo is None:
            rev_at = rev_at.replace(tzinfo=timezone.utc)
        reason_flag = reason_map.get(str(entry.get("reason") or "cessation_of_operation"), x509.ReasonFlags.cessation_of_operation)
        revoked_cert = (
            x509.RevokedCertificateBuilder()
            .serial_number(serial_int)
            .revocation_date(rev_at)
            .add_extension(x509.CRLReason(reason_flag), critical=False)
            .build()
        )
        builder = builder.add_revoked_certificate(revoked_cert)

    crl = builder.sign(private_key=ca_key, algorithm=hashes.SHA256())
    return crl.public_bytes(serialization.Encoding.DER)


def build_ocsp_response(
    target_cert_pem: str,
    issuer_cert_pem: str,
    responder_cert_pem: str,
    responder_key_pem: str,
    revoked_at: str | None = None,
    revocation_reason: str | None = None,
) -> bytes:
    """Build a signed DER RFC 6960 OCSP Response (GOOD or REVOKED)."""
    target_cert = x509.load_pem_x509_certificate(target_cert_pem.encode("ascii"))
    issuer_cert = x509.load_pem_x509_certificate(issuer_cert_pem.encode("ascii"))
    responder_cert = x509.load_pem_x509_certificate(responder_cert_pem.encode("ascii"))
    responder_key = serialization.load_pem_private_key(responder_key_pem.encode("ascii"), password=None)
    if not isinstance(responder_key, RSAPrivateKey):
        raise ValueError("OCSP responder key must be RSA")

    now = datetime.now(tz=timezone.utc)
    if revoked_at:
        rev_dt = datetime.fromisoformat(revoked_at.replace("Z", "+00:00"))
        if rev_dt.tzinfo is None:
            rev_dt = rev_dt.replace(tzinfo=timezone.utc)
        cert_status = ocsp.OCSPCertStatus.REVOKED
        reason = x509.ReasonFlags.key_compromise if revocation_reason == "key_compromise" else x509.ReasonFlags.cessation_of_operation
    else:
        rev_dt = None
        cert_status = ocsp.OCSPCertStatus.GOOD
        reason = None

    resp = (
        ocsp.OCSPResponseBuilder()
        .add_response(
            cert=target_cert,
            issuer=issuer_cert,
            algorithm=hashes.SHA256(),
            cert_status=cert_status,
            this_update=now - timedelta(minutes=1),
            next_update=now + timedelta(hours=12),
            revocation_time=rev_dt,
            revocation_reason=reason,
        )
        .responder_id(ocsp.OCSPResponderEncoding.HASH, responder_cert)
        .certificates([responder_cert])
        .sign(responder_key, hashes.SHA256())
    )
    return resp.public_bytes(serialization.Encoding.DER)


def sign_raw_pkcs1v15_sha256(private_key_pem: str, data_bytes: bytes) -> bytes:
    """Sign raw bytes (used by Tier 2B Smartcard PKCS#11 simulation/client)."""
    priv = serialization.load_pem_private_key(private_key_pem.encode("ascii"), password=None)
    if not isinstance(priv, RSAPrivateKey):
        raise ValueError("Private key must be RSA")
    return priv.sign(data_bytes, padding.PKCS1v15(), hashes.SHA256())
