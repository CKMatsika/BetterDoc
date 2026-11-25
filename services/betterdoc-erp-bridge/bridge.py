#!/usr/bin/env python3
"""
BetterDoc DMS — Windows LAN ERP Bridge Agent (Stage 3)
Outbound-only polling worker for on-premise Sage Pastel Evolution (MSSQL),
Sage Pastel Partner (LAN CSV folders), and Belina Payroll & HR.
Requires zero inbound firewall ports.
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import socket
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

AGENT_VERSION = "1.0.0"


def load_config(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8") as fh:
        return json.load(fh)


def save_config(path: Path, cfg: dict[str, Any]) -> None:
    with path.open("w", encoding="utf-8") as fh:
        json.dump(cfg, fh, indent=2)


def post_json(url: str, payload: dict[str, Any], token: str | None = None) -> dict[str, Any]:
    body = json.dumps(payload).encode("utf-8")
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": f"BetterDoc-ERP-Bridge/{AGENT_VERSION}",
    }
    if token:
        headers["X-BetterDoc-Bridge-Token"] = token

    req = urllib.request.Request(url, data=body, headers=headers, method="POST")
    with urllib.request.urlopen(req, timeout=30) as resp:
        raw = resp.read().decode("utf-8")
        return json.loads(raw) if raw else {}


def read_csv_rows(csv_path: str) -> list[dict[str, str]]:
    p = Path(csv_path)
    if not p.exists():
        return []
    with p.open("r", encoding="utf-8-sig", newline="") as fh:
        return [
            {str(k).strip().lower(): str(v or "").strip() for k, v in row.items() if k}
            for row in csv.DictReader(fh)
        ]


def fetch_sage_evolution_mssql_vendors(odbc_dsn: str) -> list[dict[str, Any]]:
    import pyodbc  # type: ignore[import-not-found]

    rows: list[dict[str, Any]] = []
    with pyodbc.connect(odbc_dsn, timeout=10) as conn:
        cursor = conn.cursor()
        cursor.execute(
            "SELECT TOP 200 DCLink, Account, Name, Tax_Number, EMail FROM Vendor WHERE Account IS NOT NULL"
        )
        for rec in cursor.fetchall():
            rows.append(
                {
                    "external_id": str(rec[0]),
                    "vendor_code": str(rec[1]).strip(),
                    "name": str(rec[2]).strip(),
                    "tax_id_tin": str(rec[3] or "").strip(),
                    "email": str(rec[4] or "").strip(),
                    "currency": "USD",
                }
            )
    return rows


def execute_job(cfg: dict[str, Any], job: dict[str, Any]) -> dict[str, Any]:
    job_type = str(job.get("job_type", ""))
    payload = job.get("payload") or {}
    erp_target = str(cfg.get("erp_target", "sage_pastel_evolution"))

    if job_type == "health_check":
        return {"hostname": socket.gethostname(), "erp_target": erp_target, "ok": True}

    if job_type == "pull_vendors":
        odbc_dsn = str(cfg.get("sage_evolution_odbc", "")).strip()
        if erp_target == "sage_pastel_evolution" and odbc_dsn:
            vendors = fetch_sage_evolution_mssql_vendors(odbc_dsn)
        else:
            vendors = read_csv_rows(str(cfg.get("vendors_csv_path", "vendors.csv")))
        return {"vendors": vendors}

    if job_type == "pull_employees":
        employees = read_csv_rows(str(cfg.get("belina_employees_csv_path", "belina_employees.csv")))
        return {"employees": employees}

    if job_type == "push_invoices":
        out_dir = Path(str(cfg.get("invoice_export_dir", "./outbox_invoices")))
        out_dir.mkdir(parents=True, exist_ok=True)
        posted: list[dict[str, str]] = []
        for inv in payload.get("invoices", []):
            inv_id = str(inv.get("invoice_id", ""))
            inv_no = str(inv.get("invoice_number", inv_id))
            batch_file = out_dir / f"sage-bill-{inv_no.replace('/', '_')}.json"
            with batch_file.open("w", encoding="utf-8") as fh:
                json.dump(inv, fh, indent=2)
            posted.append({"invoice_id": inv_id, "external_id": f"LAN-{inv_no}"})
        return {"posted_invoices": posted}

    return {"note": f"Unhandled job_type: {job_type}"}


def main() -> int:
    parser = argparse.ArgumentParser(description="BetterDoc Windows LAN ERP Bridge Agent")
    parser.add_argument("--config", default="config.json", help="Path to bridge config JSON")
    parser.add_argument("--pair", default="", help="One-time pairing code generated in BetterDoc Admin")
    parser.add_argument("--run-once", action="store_true", help="Poll once and exit")
    parser.add_argument("--interval", type=int, default=15, help="Poll interval in seconds")
    args = parser.parse_args()

    cfg_path = Path(args.config)
    cfg = load_config(cfg_path)
    base_url = str(cfg["betterdoc_url"]).rstrip("/")

    if args.pair:
        res = post_json(
            f"{base_url}/api/v1/bridge/pair",
            {
                "pairing_code": args.pair.strip(),
                "hostname": socket.gethostname(),
                "agent_version": AGENT_VERSION,
            },
        )
        cfg["bridge_token"] = res["bridge_token"]
        cfg["agent_id"] = res["agent_id"]
        save_config(cfg_path, cfg)
        print(f"Paired successfully as agent {res['agent_id']} ({res['erp_target']}).")
        return 0

    token = str(cfg.get("bridge_token", "")).strip()
    if not token:
        print("Error: bridge_token is empty. Run with --pair PAIR-XXXX-XXXX-XXXX first.", file=sys.stderr)
        return 1

    while True:
        try:
            poll_res = post_json(f"{base_url}/api/v1/bridge/poll", {}, token=token)
            job = poll_res.get("job")
            if job:
                job_id = str(job["id"])
                try:
                    result = execute_job(cfg, job)
                    post_json(
                        f"{base_url}/api/v1/bridge/jobs/{job_id}/complete",
                        {"status": "completed", "result": result},
                        token=token,
                    )
                    print(f"[OK] Completed job {job_id} ({job.get('job_type')})")
                except Exception as exc:
                    post_json(
                        f"{base_url}/api/v1/bridge/jobs/{job_id}/complete",
                        {"status": "failed", "error_message": str(exc)[:480]},
                        token=token,
                    )
                    print(f"[ERR] Job {job_id} failed: {exc}", file=sys.stderr)
        except urllib.error.HTTPError as exc:
            print(f"[HTTP {exc.code}] Poll failed: {exc.reason}", file=sys.stderr)
        except Exception as exc:
            print(f"[WARN] Poll error: {exc}", file=sys.stderr)

        if args.run_once:
            break
        time.sleep(max(5, args.interval))

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
