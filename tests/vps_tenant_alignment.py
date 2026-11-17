#!/usr/bin/env python3
"""Offline tests for the tenant alignment policy. No Docker, SQL or Nexi."""
import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("align_tenants", ROOT / "deploy/vps/align-tenants.py")
module = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(module)

assert len(module.required_tables()) == 23
assert set(module.ROUTE_COLUMNS) == {"iradios_stage_routed", "origin_credential_id"}
assert module.PRICES["L-PREM"] == 399
assert module.PRICES["B-PREM"] == 9900
assert module.PRICES["ADD-DSP"] == 2790
assert module.PRICES["ADD-AN"] == 690
assert module.PRICES["ADD-ADS"] == 890
assert module.PRICES["ADV-BUDGET"] == 100

# Even an approved legacy price must not be rewritten automatically.
module.catalog = lambda tenant, app: {"B-PREM": (11900, "EUR", 1)}
try:
    module.preflight(1)
    raise AssertionError("Legacy production price was silently accepted for overwrite")
except ValueError:
    pass
module.catalog = lambda tenant, app: {"B-PREM": (500, "EUR", 1)}
try:
    module.preflight(1)
    raise AssertionError("Unapproved production price was accepted")
except ValueError:
    pass

# SQL DDL must be fixed, reviewed CREATE TABLE IF NOT EXISTS. A deliberately
# unexpected extra statement must be rejected before any SQL execution.
executed = []
module.catalog = lambda tenant, app: {}
module.sql = lambda statement, tenant=None: executed.append((statement, tenant)) or []
module.schema_rows = lambda tenant, category: set()
module.migrate_tables("iluma_production")
assert len(executed) == len(module.DDL) + len(module.ROUTE_COLUMNS) + 1
assert all(tenant == "iluma_production" for _, tenant in executed)
assert any("ADD KEY ix_iradios_stage_routed" in statement for statement, _ in executed)
assert not any("DROP " in statement.upper() or "DELETE " in statement.upper()
               for statement, _ in executed)
# The write routine must also refuse automatic legacy-price updates.
module.catalog = lambda tenant, app: {"B-PREM": (11900, "EUR", 1)}
before = len(executed)
try:
    module.reconcile_catalog("iluma_production", 1)
    raise AssertionError("Legacy catalog price was changed")
except ValueError:
    pass
assert len(executed) == before

print("PASS: tenant alignment prices, reviewed schema, non-destructive migrations")
