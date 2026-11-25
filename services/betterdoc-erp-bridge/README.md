# BetterDoc Windows LAN ERP Bridge Agent (Stage 3)

Runs on a client's internal Windows Server alongside **Sage Pastel Evolution (MSSQL)**, **Sage Pastel Partner**, or **Belina Payroll & HR**.

- **Zero inbound firewall ports required** — the agent initiates outbound HTTPS requests only to BetterDoc DMS (`/api/v1/bridge/*`).
- Uses a one-time pairing code exchanged for a SHA-256 hashed machine token (`bdb_...`) that can be revoked immediately from **Admin > Integrations & ERP**.

## Quick Setup (Windows Server)

1. Register a new **LAN ERP Bridge Agent** in BetterDoc (`Admin > Integrations & ERP`) and copy the one-time pairing code (`PAIR-XXXX-XXXX-XXXX`).
2. Download the generated `bridge-config.json` (or copy `config.example.json` to `config.json`) into this folder.
3. Pair the agent once:
   ```powershell
   python bridge.py --config config.json --pair PAIR-XXXX-XXXX-XXXX
   ```
4. Start polling (or register under Windows Task Scheduler / NSSM as a Windows Service):
   ```powershell
   python bridge.py --config config.json --interval 15
   ```
5. *(Optional for live Sage Pastel Evolution MSSQL)* Install `pyodbc`:
   ```powershell
   pip install pyodbc
   ```
