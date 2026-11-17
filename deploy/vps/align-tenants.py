#!/usr/bin/env python3
"""Align the two isolated Gateway tenant schemas and approved iRadios SKU prices.

Default is READ-ONLY. --apply backs up ALL three databases before any DDL/DML.
Never copies customer, payment, mandate, card or webhook rows between tenants.
No Nexi network calls, production unlocks or worker activation are performed.
"""
import argparse
from pathlib import Path
import os
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
TENANTS = ("iluma_staging", "iluma_production")
DDL = (
    "database/tenant.sql",
    "database/migrations/20261110_staging_recurring_mit.sql",
    "database/migrations/20261110_staging_saved_cards.sql",
    "database/migrations/20261110_staging_winback_199.sql",
    "database/migrations/20261111_broadcaster_recurring.sql",
    "database/migrations/20261113_advertising_budget.sql",
)
# Price amounts are VAT-inclusive integer cents. Do not silently change
# pre-existing, unexpected prices in a financial system.
PRICES = {
    "L-PREM": 399, "L-WINBACK-199": 199,
    "B-PREM": 9900, "ADD-DSP": 2790, "ADD-AN": 690, "ADD-ADS": 890,
    "BOOST-T-DAY": 490, "BOOST-T-WEEK": 2990, "BOOST-T-2W": 5490,
    "BOOST-T-MONTH": 9900, "BOOST-T-YEAR": 89000,
    "BOOST-G-DAY": 2900, "BOOST-G-WEEK": 17900, "BOOST-G-2W": 34900,
    "BOOST-G-MONTH": 79900, "BOOST-G-YEAR": 848900,
    "ADV-BUDGET": 100,
}
# ONLY these four legacy-price changes have been explicitly approved.
LEGACY = {"B-PREM": 11900, "ADD-DSP": 4990, "ADD-AN": 790, "ADD-ADS": 2990}
ROUTE_COLUMNS = {
    "iradios_stage_routed": "TINYINT(1) NOT NULL DEFAULT 0",
    "origin_credential_id": "BIGINT UNSIGNED NULL DEFAULT NULL",
}


def sql(statement: str, tenant: str | None = None) -> list[str]:
    if tenant is not None and tenant not in TENANTS:
        raise ValueError("Unexpected tenant")
    command = [
        "docker", "compose", "-f", str(ROOT / "compose.vps.yml"),
        "exec", "-T", "db", "sh", "-c",
        'MYSQL_PWD="$(cat /run/secrets/mysql_root_password)" exec mysql -uroot '
        '--protocol=socket --batch --skip-column-names "$@"',
        "sh",
    ]
    if tenant:
        command.append(tenant)
    result = subprocess.run(
        command, input=statement, text=True, capture_output=True,
        cwd=ROOT, timeout=180,
    )
    if result.returncode:
        match = re.search(r"ERROR\s+(\d+)", result.stderr)
        code = match.group(1) if match else "unknown"
        raise RuntimeError(f"MySQL rejected operation (code={code}); no blind retry")
    return result.stdout.strip().splitlines()


def required_tables() -> set[str]:
    tables: set[str] = set()
    for path in DDL:
        data = (ROOT / path).read_text(encoding="utf-8")
        found = re.findall(r"(?im)^\s*CREATE TABLE IF NOT EXISTS\s+(\w+)", data)
        if not found:
            raise RuntimeError(f"No tables found in reviewed migration {path}")
        tables.update(found)
    return tables


def schema_rows(tenant: str, category: str) -> set[str]:
    if category == "tables":
        statement = (
            "SELECT table_name FROM information_schema.tables "
            f"WHERE table_schema='{tenant}' AND table_type='BASE TABLE'"
        )
    elif category == "columns":
        statement = (
            "SELECT CONCAT_WS('|',table_name,column_name,column_type,is_nullable,"
            "COALESCE(column_default,'<NULL>'),extra,"
            "COALESCE(character_set_name,''),COALESCE(collation_name,'')) "
            f"FROM information_schema.columns WHERE table_schema='{tenant}'"
        )
    elif category == "indexes":
        statement = (
            "SELECT CONCAT_WS('|',table_name,index_name,non_unique,seq_in_index,"
            "column_name,COALESCE(sub_part,0),index_type) "
            f"FROM information_schema.statistics WHERE table_schema='{tenant}'"
        )
    elif category == "foreign_keys":
        statement = (
            "SELECT CONCAT_WS('|',table_name,column_name,referenced_table_name,"
            "referenced_column_name,ordinal_position) "
            f"FROM information_schema.key_column_usage WHERE table_schema='{tenant}' "
            "AND referenced_table_name IS NOT NULL"
        )
    else:
        raise ValueError("Invalid schema category")
    return set(sql(statement))


def catalog(tenant: str, application_id: int) -> dict[str, tuple[int, str, int]]:
    if "products" not in schema_rows(tenant, "tables"):
        return {}
    rows = sql(
        "SELECT sku,amount_cents,currency,enabled FROM products "
        f"WHERE application_id={application_id}", tenant,
    )
    result = {}
    for row in rows:
        fields = row.split("\t")
        if len(fields) != 4:
            raise RuntimeError("Unexpected catalog query output")
        sku, amount, currency, enabled = fields
        result[sku] = (int(amount), currency, int(enabled))
    return result


def verify(application_id: int) -> bool:
    ok = True
    expected = required_tables()
    print("READ-ONLY TENANT AUDIT")
    for tenant in TENANTS:
        tables = schema_rows(tenant, "tables")
        missing = sorted(expected - tables)
        print(f"{tenant}: {len(tables)} tables, missing required: {missing or 'none'}")
        if missing:
            ok = False
        columns = schema_rows(tenant, "columns")
        for name in ROUTE_COLUMNS:
            if not any(item.startswith("payments|" + name + "|") for item in columns):
                print(f"{tenant}: missing payments.{name}")
                ok = False
        indexes = schema_rows(tenant, "indexes")
        if not any(item.startswith("payments|ix_iradios_stage_routed|") for item in indexes):
            print(f"{tenant}: missing payment route index")
            ok = False
        observed = catalog(tenant, application_id)
        for sku, price in PRICES.items():
            if observed.get(sku) != (price, "EUR", 1):
                print(f"{tenant}: SKU {sku} missing or wrong price/currency/enabled")
                ok = False
    for category in ("tables", "columns", "indexes", "foreign_keys"):
        staging = schema_rows(TENANTS[0], category)
        production = schema_rows(TENANTS[1], category)
        if staging != production:
            ok = False
            only_stage = sorted(staging - production)
            only_prod = sorted(production - staging)
            print(f"{category}: ONLY staging={len(only_stage)} ONLY production={len(only_prod)}")
            for entry in only_stage[:6]:
                print(f"  staging only: {entry}")
            for entry in only_prod[:6]:
                print(f"  production only: {entry}")
    print("PASS: tenant schema and approved catalog parity" if ok else
          "STOP: tenants not ready; see differences above")
    return ok


def preflight(application_id: int) -> None:
    for tenant in TENANTS:
        existing = catalog(tenant, application_id)
        for sku, expected in PRICES.items():
            if sku not in existing:
                continue
            value = existing[sku]
            if value == (expected, "EUR", 1):
                continue
            raise ValueError(
                f"{tenant}: {sku} differs from approved catalog; "
                "stop and review historical price manually"
            )


def migrate_tables(tenant: str) -> None:
    for path in DDL:
        # All migration files contain only reviewed, idempotent CREATE TABLE.
        source = (ROOT / path).read_text(encoding="utf-8")
        stripped = re.sub(r"(?m)^\s*--[^\n]*$", "", source).strip()
        statements = [part.strip() for part in stripped.split(";") if part.strip()]
        if not statements or any(
            re.match(r"(?is)^CREATE TABLE IF NOT EXISTS\b", part) is None
            for part in statements
        ):
            raise ValueError(f"Non-idempotent SQL in {path}; refusing")
        sql(source, tenant)
    columns = schema_rows(tenant, "columns")
    for name, spec in ROUTE_COLUMNS.items():
        if not any(item.startswith("payments|" + name + "|") for item in columns):
            sql(f"ALTER TABLE payments ADD COLUMN {name} {spec};", tenant)
    indexes = schema_rows(tenant, "indexes")
    if not any(item.startswith("payments|ix_iradios_stage_routed|") for item in indexes):
        sql("ALTER TABLE payments ADD KEY ix_iradios_stage_routed "
            "(iradios_stage_routed,origin_credential_id);", tenant)


def reconcile_catalog(tenant: str, application_id: int) -> None:
    existing = catalog(tenant, application_id)
    # Validate ALL existing scoped prices before the first INSERT. In particular,
    # legacy broadcaster prices are not automatically rewritten in financial data.
    for sku, price in PRICES.items():
        current = existing.get(sku)
        if current is not None and current != (price, "EUR", 1):
            raise ValueError(f"Unexpected catalog drift for {tenant}/{sku}; no writes")
    for sku, price in PRICES.items():
        if sku in existing:
            continue
        # SKU is a fixed reviewed literal, never external input.
        sql("INSERT INTO products(application_id,sku,title,amount_cents,currency,enabled) "
            f"VALUES({application_id},'{sku}','{sku}',{price},'EUR',1);", tenant)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--apply", action="store_true",
                        help="Backup then align table structures and approved SKU prices")
    parser.add_argument("--verified-offsite-restore", action="store_true",
                        help="Confirm encrypted off-host backup and isolated restore of current data")
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise ValueError("Run via sudo on Gateway VPS; private root MySQL socket required")
    if args.verified_offsite_restore and not args.apply:
        raise ValueError("Restore confirmation is only valid with --apply")
    if args.apply:
        if not args.verified_offsite_restore:
            raise ValueError("STOP: verified encrypted off-host backup and isolated restore required")
        private = Path("/etc/iluma-gateway/iluma-gateway.env")
        if not private.is_file() or private.is_symlink() or private.stat().st_mode & 0o077:
            raise ValueError("STOP: missing or insecure private runtime environment")
        flags = {
            "PRODUCTION_ENABLED", "IRADIOS_STAGE_PRODUCTION_ENABLED",
            "MIT_PRODUCTION_EXECUTION_ENABLED", "MIT_PRODUCTION_UAT_APPROVED",
            "BROADCASTER_MIT_PRODUCTION_EXECUTION_ENABLED",
            "BROADCASTER_MIT_PRODUCTION_UAT_APPROVED",
        }
        values = {}
        for raw in private.read_text().splitlines():
            if "=" not in raw or raw.lstrip().startswith("#"):
                continue
            key, value = raw.split("=", 1)
            if key.strip() in flags:
                values[key.strip()] = value.strip().strip(chr(34)).strip(chr(39))
        if any(values.get(flag, "0") not in ("", "0") for flag in flags):
            raise ValueError("STOP: production checkout/MIT must stay locked during DDL")
    application = sql(
        "SELECT id FROM iluma_registry.applications WHERE slug='iradiosgr' LIMIT 2"
    )
    if len(application) != 1 or not application[0].isdigit():
        raise RuntimeError("Expected exactly one iradiosgr registry application")
    app_id = int(application[0])
    if not args.apply:
        if not verify(app_id):
            sys.exit(2)
        return
    preflight(app_id)
    print("Backing up all Gateway databases BEFORE structural or catalog changes")
    subprocess.run(
        ["python3", "deploy/vps/backup-db.py"], check=True, cwd=ROOT,
    )
    for tenant in TENANTS:
        migrate_tables(tenant)
        reconcile_catalog(tenant, app_id)
    if not verify(app_id):
        raise RuntimeError("Schema/catalog parity not proven; keep live charges OFF")
    print("PASS: alignment completed. No historical financial rows copied or deleted.")
    print("Production charging flags and workers were NOT enabled.")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(f"STOP: {error}", file=sys.stderr)
        sys.exit(1)
