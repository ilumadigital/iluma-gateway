#!/usr/bin/env python3
"""Destructive tests in a disposable CI MySQL service ONLY. Never a VPS tool."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
if os.environ.get("GITHUB_ACTIONS") != "true" or not os.environ.get("BILLING_MYSQL_CONTAINER"):
    raise SystemExit("CI fixture only")
spec = importlib.util.spec_from_file_location("production_billing", ROOT / "deploy/vps/production-billing.py")
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
container = os.environ["BILLING_MYSQL_CONTAINER"]
assert container and all(c in "0123456789abcdef" for c in container)
m.docker_db = lambda program: ["docker", "exec", "-i", "-e",
    "MYSQL_PWD=integration-only-password", container, program, "-uroot", "--protocol=socket"]
m.mysql("CREATE DATABASE iluma_registry; CREATE DATABASE iluma_staging; CREATE DATABASE iluma_production;", "mysql")
m.mysql((ROOT / "database/registry.sql").read_bytes(), "iluma_registry")
for db in ("iluma_staging", "iluma_production"):
    m.mysql((ROOT / "database/tenant.sql").read_bytes(), db)
m.mysql("CREATE USER 'iluma_production_app'@'%' IDENTIFIED BY '" + "a"*64 + "';"
        "GRANT SELECT ON iluma_production.* TO 'iluma_production_app'@'%';"
        "CREATE USER 'iluma_registry_app'@'%' IDENTIFIED BY '" + "b"*64 + "';"
        "GRANT SELECT,INSERT,UPDATE,DELETE ON iluma_registry.* TO 'iluma_registry_app'@'%';")
env = dict(os.environ, MASTER_KEY_B64="S0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0s=",
           APP_ENV="production", PRODUCTION_ENABLED="1", IRADIOS_STAGE_PRODUCTION_ENABLED="1",
           IRADIOS_STAGE_PRODUCTION_WEBHOOK="https://stage.iradios.gr/api/gateway/webhook",
           NEXI_PRODUCTION_BASE="https://xpay.nexigroup.com/api/phoenix-0.0/psp",
           PRODUCTION_DSN="mysql:host=127.0.0.1;port=3367;dbname=iluma_production;charset=utf8mb4",
           PRODUCTION_USER="iluma_production_app", PRODUCTION_PASSWORD="a"*64,
           REGISTRY_DSN="mysql:host=127.0.0.1;port=3367;dbname=iluma_registry;charset=utf8mb4",
           REGISTRY_USER="iluma_registry_app", REGISTRY_PASSWORD="b"*64)
box = subprocess.check_output(["php", "-r",
    'require "src/bootstrap.php";echo \\Iluma\\Gateway\\Security::encrypt("fixture-provider");'],
    cwd=ROOT, env=env, text=True)
assert all(c.isalnum() or c in "+/=_-" for c in box)
m.mysql("INSERT INTO application_credentials(application_id,environment,key_hash,key_prefix,secret_box)"
        " VALUES(1,'production',REPEAT('c',64),'fixture','" + box + "');"
        "INSERT INTO nexi_credentials(environment,secret_box,updated_at)"
        " VALUES('production','" + box + "',UTC_TIMESTAMP());", "iluma_registry")
manifest = json.loads(m.MANIFEST.read_text())
readiness_cmd = ["php", "-r", 'require "src/bootstrap.php";echo json_encode('
    '\\Iluma\\Gateway\\ProductionBillingReadiness::inspect('
    '["environment"=>"production","slug"=>"iradiosgr","application_id"=>1]));']
def readiness():
    return json.loads(subprocess.check_output(readiness_cmd, cwd=ROOT, env=env, text=True))
assert readiness()["ready"] is False
initial = m.audit(manifest)
assert len(initial["missing_tables"]) == 8
assert len(initial["missing_columns"]) == 2
assert len(initial["missing_products"]) == 17
assert initial["missing_dml"] == ["DELETE", "INSERT", "UPDATE"]
staging_before, registry_before = m.snapshot("iluma_staging"), m.snapshot("iluma_registry")
with tempfile.TemporaryDirectory() as directory:
    m.BACKUPS = Path(directory)
    before = m.snapshot()
    try:
        m.apply(manifest, False)
        raise AssertionError("implicit GRANT allowed")
    except ValueError:
        pass
    assert m.snapshot() == before and not list(m.BACKUPS.iterdir())
    original_snapshot = m.snapshot
    m.snapshot = lambda db=m.DB: {} if db.startswith("iluma_restore_check_") else original_snapshot(db)
    try:
        m.verified_backup()
        raise AssertionError("bad restore proof accepted")
    except ValueError:
        pass
    finally:
        m.snapshot = original_snapshot
    assert m.snapshot() == before
    m.apply(manifest, True)
    assert not m.needs_changes(m.audit(manifest))
    assert readiness()["ready"] is True
    assert len(m.table_names()) == 23
    assert m.scalar("SELECT COUNT(*) FROM payments") == "0"
    backups = list(m.BACKUPS.iterdir())
    m.apply(manifest, True)
    assert list(m.BACKUPS.iterdir()) == backups
    m.mysql("UPDATE products SET amount_cents=11900 WHERE sku='B-PREM'")
    try:
        m.apply(manifest, True)
        raise AssertionError("conflicting price overwritten")
    except ValueError:
        pass
    assert m.scalar("SELECT amount_cents FROM products WHERE sku='B-PREM'") == "11900"
    assert readiness()["ready"] is False
    m.mysql("UPDATE products SET amount_cents=9900 WHERE sku='B-PREM'")
    m.mysql("ALTER TABLE saved_card_agreements MODIFY status VARCHAR(31) NOT NULL DEFAULT 'pending'")
    assert "schema:saved_card_agreements.status" in m.audit(manifest)["blockers"]
    assert readiness()["ready"] is False
    m.mysql("ALTER TABLE saved_card_agreements MODIFY status VARCHAR(32) NOT NULL DEFAULT 'pending'")
    m.mysql("INSERT INTO customers(id,application_id,external_user_id,email)"
            " VALUES(REPEAT('d',32),1,'fixture','fixture@example.test');"
            "DELETE FROM products WHERE sku='ADV-BUDGET'")
    before = m.snapshot()
    try:
        m.apply(manifest, True)
        raise AssertionError("occupied production bootstrap accepted")
    except ValueError:
        pass
    assert m.snapshot() == before
assert m.snapshot("iluma_staging") == staging_before
assert m.snapshot("iluma_registry") == registry_before
print("PASS: restore proof, migration, DML, idempotency, drift/occupied refusal, PHP readiness, tenant isolation")
