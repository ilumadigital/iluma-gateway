#!/usr/bin/env python3
"""Offline static and mocked-SQL tests for the iRadios-scoped clean-launch gate."""
import contextlib
import importlib.util
import io
from pathlib import Path
from unittest import mock

root=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("clean",root/"deploy/vps/clean-production-launch.py")
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

assert mod.APP_SLUG=="iradiosgr"
assert "webhook_events" in mod.SCOPES
assert "recurring_mandates" in mod.REQUIRED_ZERO
assert "broadcaster_mandates" in mod.REQUIRED_ZERO

queries=[]
def fake_query(sql):
    queries.append(sql)
    if "FROM iluma_registry.applications" in sql:
        return [["4"]]
    if "FROM information_schema.TABLES" in sql:
        return [[k] for k in mod.SCOPES]
    if "SELECT COUNT(*)" in sql:
        return [["0"]]
    raise AssertionError("Unexpected query")

with mock.patch.object(mod,"query",fake_query):
    app,counts,checks,blockers=mod.audit()
    assert app==4 and blockers==[]
    assert len(counts)==len(mod.SCOPES)
    assert all(v==0 for v in counts.values())
    assert all(v==0 for v in checks.values())

scoped=[s for s in queries if "SELECT COUNT(*) FROM iluma_production." in s]
assert len(scoped)>=len(mod.SCOPES)
for sql in scoped:
    assert "application_id=4" in sql, sql
assert any("JOIN iluma_production.payments p" in s for s in scoped)
assert any("JOIN iluma_production.recurring_mandates m" in s for s in scoped)
assert any("JOIN iluma_production.broadcaster_mandates m" in s for s in scoped)
assert any("JOIN iluma_production.outgoing_events e" in s for s in scoped)
assert not any("DELETE " in s or "UPDATE " in s for s in scoped)

with mock.patch.object(mod,"query",lambda sql: [["0"]] if "COUNT" in sql else []):
    try:
        mod.application_id()
    except RuntimeError:
        pass
    else:
        raise AssertionError("Missing application must stop")

with mock.patch.object(mod,"scope_count",side_effect=lambda name,app,extra="":1 if name=="payments" else 0), \
     mock.patch.object(mod,"existing_tables",return_value=set(mod.SCOPES)), \
     mock.patch.object(mod,"application_id",return_value=4):
    _,_,_,blockers=mod.audit()
    assert any(s.startswith("payments: 1") for s in blockers)

print("PASS: clean launch audit is app-scoped and fails closed")
