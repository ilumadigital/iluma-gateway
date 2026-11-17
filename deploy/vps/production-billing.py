#!/usr/bin/env python3
"""Production-only billing audit/bootstrap. Default is read-only.
--apply backs up and restores the snapshot into a temporary verification database
before additive DDL. Never calls Nexi, starts a worker, changes an ENV, alters an
existing price/credential/route, or runs against staging.
"""
import argparse
import fcntl
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import uuid
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parents[2]
CONF = Path("/etc/iluma-gateway/iluma-gateway.env")
DB = "iluma_production"
USER = "iluma_production_app"
MANIFEST = ROOT / "database/production-billing.json"
DDL = ROOT / "database/migrations/20261117_production_billing_tables.sql"
BACKUPS = Path("/root/gateway-migration/backups")
DML = {"SELECT", "INSERT", "UPDATE", "DELETE"}
BASE = {"customers", "products", "payments", "subscriptions", "billing_cycles",
        "customer_application_links", "payment_attempts", "payment_methods",
        "nexi_contracts", "refunds", "webhook_events", "outgoing_events",
        "event_deliveries", "payment_logs", "settings"}


def quote(value):
    # Values passed here are controlled identifiers/SKUs, not secrets.
    if not re.fullmatch(r"[A-Za-z0-9_@%.-]+", str(value)):
        raise ValueError("Unexpected SQL identifier/value")
    return "'" + str(value) + "'"


def docker_db(program):
    return ["docker", "compose", "-f", str(ROOT / "compose.vps.yml"),
            "exec", "-T", "db", "sh", "-c",
            'MYSQL_PWD="$(cat /run/secrets/mysql_root_password)" exec "$@"',
            "sh", program, "-uroot", "--protocol=socket"]


def execute(command, data=None):
    result = subprocess.run(command, input=data, capture_output=True, cwd=ROOT)
    if result.returncode:
        match = re.search(rb"ERROR\s+(\d{3,5})", result.stderr)
        code = match.group(1).decode() if match else "unknown"
        raise RuntimeError("Database command failed; mysql_code=" + code)
    return result.stdout


def mysql(sql, database=DB):
    if not re.fullmatch(r"[a-z0-9_]+", database):
        raise ValueError("Invalid database")
    raw = sql.encode() if isinstance(sql, str) else sql
    return execute(docker_db("mysql") +
                   ["--batch", "--raw", "--skip-column-names",
                    "--database=" + database], raw).decode()


def rows(sql, database=DB):
    return [line.split("\t") for line in mysql(sql, database).splitlines()]


def scalar(sql, database=DB):
    return rows(sql, database)[0][0]


def normalize_type(value):
    return re.sub(r"\b(tinyint|smallint|int|bigint)\(\d+\)", r"\1", value.lower())


def read_config():
    if not CONF.is_file() or CONF.is_symlink() or CONF.stat().st_mode & 0o077:
        raise ValueError("Private configuration absent or insecure")
    env = {}
    for line in CONF.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        match = re.fullmatch(r"([A-Z][A-Z0-9_]*)\s*=\s*(.*)", line)
        if not match or match[1] in env:
            raise ValueError("Malformed or duplicate private configuration key")
        value = match[2].strip()
        if value.startswith(("'", '"')):
            if len(value) < 2 or value[-1] != value[0]:
                raise ValueError("Malformed quoted private configuration")
            value = value[1:-1]
        env[match[1]] = value
    expected = {
        "PRODUCTION_DSN": "mysql:host=db;port=3306;dbname=iluma_production;charset=utf8mb4",
        "PRODUCTION_USER": USER,
        "APP_ENV": "production", "PRODUCTION_ENABLED": "1",
        "IRADIOS_STAGE_PRODUCTION_ENABLED": "1",
        "IRADIOS_STAGE_PRODUCTION_WEBHOOK": "https://stage.iradios.gr/api/gateway/webhook",
    }
    for key, value in expected.items():
        if env.get(key) != value:
            raise ValueError("Unexpected runtime setting: " + key)
    # Initial restoration must not silently arm automatic charges.
    for key in ("MIT_PRODUCTION_EXECUTION_ENABLED", "MIT_PRODUCTION_UAT_APPROVED",
                "BROADCASTER_MIT_PRODUCTION_EXECUTION_ENABLED",
                "BROADCASTER_MIT_PRODUCTION_UAT_APPROVED"):
        if env.get(key, "0") not in ("", "0"):
            raise ValueError("Initial restoration requires MIT disabled: " + key)


def table_names(database=DB):
    return {r[0] for r in rows("SHOW TABLES", database)}


def columns(table, database=DB):
    result = rows("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS"
                  " WHERE TABLE_SCHEMA=" + quote(database) + " AND TABLE_NAME=" + quote(table))
    return {r[0]: {"type": normalize_type(r[1]), "nullable": r[2] == "YES"} for r in result}


def schema_errors(table, spec, database=DB):
    actual = columns(table, database)
    errors = []
    for name, expected in spec["columns"].items():
        desired = {"type": normalize_type(expected["type"]), "nullable": expected["nullable"]}
        if actual.get(name) != desired:
            errors.append("schema:" + table + "." + name)
    indices = {}
    for name, unique, col in rows(
            "SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME FROM information_schema.STATISTICS"
            " WHERE TABLE_SCHEMA=" + quote(database) + " AND TABLE_NAME=" + quote(table) +
            " ORDER BY INDEX_NAME,SEQ_IN_INDEX"):
        entry = indices.setdefault(name, {"columns": [], "unique": unique == "0", "primary": name == "PRIMARY"})
        entry["columns"].append(col)
    for expected in spec.get("indexes", []):
        if not any(ix["columns"] == expected["columns"] and
                   (not expected["unique"] or ix["unique"]) and
                   (not expected.get("primary") or ix["primary"]) for ix in indices.values()):
            errors.append("index:" + table + "." + ",".join(expected["columns"]))
    actual_fks = {(r[0], r[1], r[2], r[3]) for r in rows(
        "SELECT COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME,REFERENCED_TABLE_SCHEMA"
        " FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=" + quote(database) +
        " AND TABLE_NAME=" + quote(table) + " AND REFERENCED_TABLE_NAME IS NOT NULL")}
    for expected in spec.get("foreign", []):
        for col, ref in zip(expected["columns"], expected["references"]):
            if (col, expected["table"], ref, database) not in actual_fks:
                errors.append("foreign-key:" + table + "." + col)
    return errors


def catalog_plan(expected, actual):
    missing, errors = [], []
    for sku, cents in expected.items():
        row = actual.get(sku)
        if row is None:
            missing.append(sku)
        elif row != (cents, "EUR", 1):
            errors.append("catalog-conflict:" + sku)
    return missing, errors


def audit(manifest):
    tables = table_names()
    blockers = ["missing-base:" + t for t in sorted(BASE - tables)]
    app = rows("SELECT id,enabled FROM applications WHERE slug='iradiosgr'", "iluma_registry")
    app_id = int(app[0][0]) if len(app) == 1 and app[0][1] == "1" else None
    if not app_id:
        blockers.append("active-iradios-application")
    elif int(scalar("SELECT COUNT(*) FROM application_credentials WHERE application_id=" +
                    str(app_id) + " AND environment='production' AND enabled=1", "iluma_registry")) < 1:
        blockers.append("active-production-credential")
    if int(scalar("SELECT COUNT(*) FROM nexi_credentials WHERE environment='production'"
                  " AND LENGTH(secret_box)>0", "iluma_registry")) != 1:
        blockers.append("production-provider-credential")
    if int(scalar("SELECT COUNT(*) FROM mysql.user WHERE User='iluma_production_app' AND Host='%'")) != 1:
        blockers.append("production-runtime-user")
    missing_tables = sorted(set(manifest["tables"]) - tables)
    for name, spec in manifest["tables"].items():
        if name in tables:
            blockers.extend(schema_errors(name, spec))
    payment_cols = columns("payments")
    missing_columns = []
    for name, expected in manifest["routing"].items():
        if name not in payment_cols:
            missing_columns.append(name)
        elif payment_cols[name] != {"type": normalize_type(expected["type"]), "nullable": expected["nullable"]}:
            blockers.append("routing-column-conflict:" + name)
    route_index = rows("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE"
                      " TABLE_SCHEMA='iluma_production' AND TABLE_NAME='payments'"
                      " AND INDEX_NAME='ix_iradios_stage_routed' ORDER BY SEQ_IN_INDEX")
    if route_index and [r[0] for r in route_index] != ["iradios_stage_routed", "origin_credential_id"]:
        blockers.append("routing-index-conflict")
    actual = {}
    if "products" in tables and app_id:
        actual = {r[0]: (int(r[1]), r[2], int(r[3])) for r in rows(
            "SELECT sku,amount_cents,currency,enabled FROM products WHERE application_id=" + str(app_id))}
    missing_products, conflicts = catalog_plan(manifest["catalog"], actual)
    blockers.extend(conflicts)
    # This bootstrap is for the verified pre-launch database, not a live-ledger upgrade.
    occupied = []
    for name in sorted((BASE | set(manifest["tables"])) & tables - {"products", "settings"}):
        if int(scalar("SELECT COUNT(*) FROM " + name)):
            occupied.append(name)
    grants = {r[0] for r in rows(
        "SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES"
        " WHERE TABLE_SCHEMA='iluma_production' AND GRANTEE=CONCAT(CHAR(39),"
        "'iluma_production_app',CHAR(39),'@',CHAR(39),'%',CHAR(39))")}
    return {"application_id": app_id, "missing_tables": missing_tables,
            "missing_columns": missing_columns, "missing_index": not route_index,
            "missing_products": missing_products, "missing_dml": sorted(DML - grants),
            "occupied_tables": occupied, "blockers": blockers}


def needs_changes(plan):
    return bool(plan["blockers"] or plan["missing_tables"] or plan["missing_columns"] or
                plan["missing_index"] or plan["missing_products"] or plan["missing_dml"])


def migration_statements():
    source = re.sub(r"^\s*--[^\n]*$", "", DDL.read_text(), flags=re.M)
    statements = [s.strip() for s in source.split(";") if s.strip()]
    manifest = json.loads(MANIFEST.read_text())
    names = []
    for stmt in statements:
        match = re.match(r"^CREATE TABLE IF NOT EXISTS ([a-z_]+)\s*\(", stmt)
        if not match:
            raise ValueError("Unexpected production migration statement")
        names.append(match[1])
    if len(names) != 8 or set(names) != set(manifest["tables"]):
        raise ValueError("Unexpected production migration table set")
    return dict(zip(names, statements))


def snapshot(database=DB):
    # No data leaves this function; only schema and per-table row counts.
    return {name: (mysql("SHOW CREATE TABLE " + name, database).replace(database, DB),
                   scalar("SELECT COUNT(*) FROM " + name, database))
            for name in sorted(table_names(database))}


def verified_backup():
    for table, field in (("TRIGGERS", "TRIGGER_SCHEMA"), ("ROUTINES", "ROUTINE_SCHEMA"),
                         ("EVENTS", "EVENT_SCHEMA")):
        if int(scalar("SELECT COUNT(*) FROM information_schema." + table +
                      " WHERE " + field + "='iluma_production'")):
            raise ValueError("Unexpected programmable database objects; manual review required")
    before = snapshot()
    dump = execute(docker_db("mysqldump") + [
        "--single-transaction", "--quick", "--hex-blob", "--skip-triggers",
        "--skip-routines", "--skip-events", "--no-tablespaces", "--set-gtid-purged=OFF", DB])
    if not dump or re.search(rb"(?im)^\s*(USE\s|CREATE\s+DATABASE|DROP\s+DATABASE)", dump):
        raise ValueError("Unexpected backup structure")
    BACKUPS.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(BACKUPS, 0o700)
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    suffix = uuid.uuid4().hex[:12]
    path = BACKUPS / (DB + "-billing-" + stamp + "-" + suffix + ".sql.gz")
    compressed = gzip.compress(dump, mtime=0)
    fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(fd, "wb") as out:
        out.write(compressed)
        out.flush()
        os.fsync(out.fileno())
    saved = path.read_bytes()
    if saved != compressed:
        raise ValueError("Backup verification failed")
    temporary = "iluma_restore_check_" + suffix
    # Only this freshly created, random verification DB can be dropped.
    mysql("CREATE DATABASE " + temporary + " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
    try:
        mysql(gzip.decompress(saved), temporary)
        if snapshot(temporary) != before or snapshot() != before:
            raise ValueError("Restore mismatch or source changed during backup; no migration applied")
    finally:
        mysql("DROP DATABASE " + temporary)
    print("BACKUP_RESTORE_OK:", path)
    print("BACKUP_SHA256:", hashlib.sha256(saved).hexdigest())


def apply(manifest, grant_dml):
    plan = audit(manifest)
    if plan["blockers"]:
        raise ValueError("Resolve audit blockers before applying")
    if not needs_changes(plan):
        print("ALREADY_READY: no changes")
        return
    if plan["occupied_tables"]:
        raise ValueError("Existing financial/customer data requires a separate reviewed migration")
    if plan["missing_dml"] and not grant_dml:
        raise ValueError("Missing runtime DML; review audit then explicitly add --grant-runtime-dml")
    verified_backup()
    # Recheck all assumptions after the restore drill, before production writes.
    if audit(manifest) != plan:
        raise ValueError("Database state changed during verification; rerun audit")
    statements = migration_statements()
    for name in manifest["tables"]:
        if name in plan["missing_tables"]:
            mysql(statements[name])
            print("CREATED:", name)
    additions = {
        "iradios_stage_routed": "TINYINT(1) NOT NULL DEFAULT 0",
        "origin_credential_id": "BIGINT UNSIGNED NULL DEFAULT NULL",
    }
    for name in plan["missing_columns"]:
        mysql("ALTER TABLE payments ADD COLUMN " + name + " " + additions[name])
        print("ADDED_COLUMN:", name)
    if plan["missing_index"]:
        mysql("ALTER TABLE payments ADD KEY ix_iradios_stage_routed"
              " (iradios_stage_routed,origin_credential_id)")
    inserts = []
    for sku in plan["missing_products"]:
        inserts.append("INSERT INTO products(application_id,sku,title,amount_cents,currency,enabled)"
                       " VALUES(" + str(plan["application_id"]) + "," + quote(sku) + "," +
                       quote(sku) + "," + str(manifest["catalog"][sku]) + ",'EUR',1)")
    if inserts:
        mysql("START TRANSACTION;\n" + ";\n".join(inserts) + ";\nCOMMIT;")
    if plan["missing_dml"]:
        mysql("GRANT SELECT,INSERT,UPDATE,DELETE ON iluma_production.*"
              " TO 'iluma_production_app'@'%'")
    final = audit(manifest)
    if needs_changes(final):
        raise ValueError("Post-migration audit failed; keep checkout closed and review")
    print("PRODUCTION_SCHEMA_CATALOG_DML_READY: no provider call or charge executed")
    print("Next: signed application readiness and production worker verification")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--grant-runtime-dml", action="store_true")
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise ValueError("Run with sudo")
    if args.grant_runtime_dml and not args.apply:
        raise ValueError("--grant-runtime-dml requires --apply")
    read_config()
    manifest = json.loads(MANIFEST.read_text())
    if manifest["database"] != DB or manifest["application"] != "iradiosgr":
        raise ValueError("Unexpected manifest scope")
    if not args.apply:
        plan = audit(manifest)
        print(json.dumps(plan, indent=2))
        return 1 if needs_changes(plan) else 0
    with open("/run/lock/iluma-production-billing.lock", "w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        apply(manifest, args.grant_runtime_dml)
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print("STOP:", str(exc), file=sys.stderr)
        sys.exit(1)
