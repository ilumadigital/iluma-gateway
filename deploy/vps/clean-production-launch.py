#!/usr/bin/env python3
"""Read-only iRadios-only launch ledger inventory.

Never mutates MySQL, Nexi, or any application. This audit intentionally
preserves the original provider order/mandate/refund/outbox history.
"""
import argparse
import re
import subprocess
import sys

DB = "iluma_production"
APP_SLUG = "iradiosgr"

# Table names, join keys and predicates are static. There is no CLI-provided SQL.
SCOPES = {
    "payments": ("payments p", "p.application_id={app}"),
    "subscriptions": ("subscriptions s", "s.application_id={app}"),
    "recurring_mandates": ("recurring_mandates m", "m.application_id={app}"),
    "broadcaster_mandates": ("broadcaster_mandates m", "m.application_id={app}"),
    "saved_card_agreements": ("saved_card_agreements a", "a.application_id={app}"),
    "recurring_cycles": (
        "recurring_cycles c JOIN recurring_mandates m ON m.id=c.mandate_id",
        "m.application_id={app}",
    ),
    "broadcaster_cycles": (
        "broadcaster_cycles c JOIN broadcaster_mandates m ON m.id=c.mandate_id",
        "m.application_id={app}",
    ),
    "billing_cycles": (
        "billing_cycles c JOIN subscriptions s ON s.id=c.subscription_id",
        "s.application_id={app}",
    ),
    "nexi_contracts": (
        "nexi_contracts c JOIN subscriptions s ON s.id=c.subscription_id",
        "s.application_id={app}",
    ),
    "refunds": ("refunds r JOIN payments p ON p.id=r.payment_id", "p.application_id={app}"),
    "outgoing_events": ("outgoing_events e", "e.application_id={app}"),
    "payment_attempts": (
        "payment_attempts a JOIN payments p ON p.id=a.payment_id", "p.application_id={app}",
    ),
    "advertising_budget_scopes": ("advertising_budget_scopes b", "b.application_id={app}"),
    "broadcaster_boost_scopes": ("broadcaster_boost_scopes b", "b.application_id={app}"),
    "winback_claims": ("winback_claims c", "c.application_id={app}"),
    "customers": ("customers c", "c.application_id={app}"),
    "payment_methods": (
        "payment_methods m JOIN customers c ON c.id=m.customer_id", "c.application_id={app}",
    ),
    "webhook_events": (
        "webhook_events w JOIN payments p ON p.order_id=w.order_id", "p.application_id={app}",
    ),
    "event_deliveries": (
        "event_deliveries d JOIN outgoing_events e ON e.id=d.event_id",
        "e.application_id={app}",
    ),
    "payment_logs": (
        "payment_logs l JOIN payments p ON p.id=l.payment_id", "p.application_id={app}",
    ),
    "customer_application_links": (
        "customer_application_links l", "l.application_id={app}",
    ),
}
REQUIRED_ZERO = (
    "payments", "subscriptions", "recurring_mandates", "broadcaster_mandates",
    "saved_card_agreements", "recurring_cycles", "broadcaster_cycles",
    "billing_cycles", "nexi_contracts", "outgoing_events",
    "advertising_budget_scopes", "broadcaster_boost_scopes",
)

def query(sql):
    result = subprocess.run(
        ["docker", "exec", "-i", "iluma_gateway-db-1", "sh", "-ec",
         'export MYSQL_PWD="$(cat /run/secrets/mysql_root_password)"; '
         'exec mysql -uroot --protocol=socket -N -B --raw --skip-column-names'],
        input=sql, text=True, capture_output=True, check=False,
    )
    if result.returncode:
        raise RuntimeError("Gateway SQL audit failed (credentials redacted)")
    return [line.split("\t") for line in result.stdout.splitlines() if line.strip()]

def scalar(sql):
    rows = query(sql)
    if len(rows) != 1 or len(rows[0]) != 1:
        raise RuntimeError("Unexpected SQL result shape")
    return int(rows[0][0])

def application_id():
    rows = query("SELECT id FROM iluma_registry.applications "
                 "WHERE slug='iradiosgr' AND enabled=1")
    if len(rows) != 1 or len(rows[0]) != 1:
        raise RuntimeError("Missing or ambiguous active iRadios application")
    app = int(rows[0][0])
    if app < 1:
        raise RuntimeError("Invalid iRadios application ID")
    return app

def existing_tables():
    return {r[0] for r in query(
        "SELECT TABLE_NAME FROM information_schema.TABLES "
        "WHERE TABLE_SCHEMA='iluma_production' AND TABLE_TYPE='BASE TABLE'"
    )}

def scope_count(table, app, extra=""):
    if table not in SCOPES or not isinstance(app, int) or app < 1:
        raise ValueError("Unexpected financial scope")
    source, condition = SCOPES[table]
    if not re.fullmatch(r"[a-z_]+", table):
        raise ValueError("Invalid table")
    # Qualify every table alias for cross-schema safety.
    qualified = re.sub(r"\b(?:FROM|JOIN)\s+([a-z_]+)\b", lambda match:
                       match.group(0).replace(match.group(1), DB + "." + match.group(1)),
                       "FROM " + source, flags=re.I)
    tail = f" AND ({extra})" if extra else ""
    return scalar(f"SELECT COUNT(*) {qualified} WHERE {condition.format(app=app)}{tail}")

def audit():
    app = application_id()
    present = existing_tables()
    counts = {name: scope_count(name, app) if name in present else None
              for name in SCOPES}
    checks = {
        "payment_unknown_or_open": scope_count(
            "payments", app, "p.status IN ('unknown','pending','redirect','creating')"
        ) if "payments" in present else None,
        "unrefunded_paid_payments": scope_count(
            "payments", app, "p.status='paid' AND p.amount_cents > COALESCE("
            "(SELECT SUM(r.amount_cents) FROM iluma_production.refunds r "
            "WHERE r.payment_id=p.id AND r.confirmed_at IS NOT NULL),0)"
        ) if {"payments", "refunds"} <= present else None,
        "undelivered_events": scope_count(
            "outgoing_events", app, "e.status<>'delivered'"
        ) if "outgoing_events" in present else None,
        "live_listener_mandates": scope_count(
            "recurring_mandates", app, "m.status<>'cancelled' OR m.deactivated_at IS NULL"
        ) if "recurring_mandates" in present else None,
        "live_broadcaster_mandates": scope_count(
            "broadcaster_mandates", app, "m.status<>'cancelled' OR m.deactivated_at IS NULL"
        ) if "broadcaster_mandates" in present else None,
    }
    blockers = [f"{k}: {counts[k] if counts[k] is not None else 'MISSING'}"
                for k in REQUIRED_ZERO if counts[k] is None or counts[k] != 0]
    blockers += [f"{k}: {v if v is not None else 'MISSING'}"
                 for k, v in checks.items() if v is None or v > 0]
    return app, counts, checks, blockers

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--assert-clean", action="store_true",
                        help="Exit nonzero until the iRadios operational production ledger is empty")
    args = parser.parse_args()
    try:
        app, counts, checks, blockers = audit()
    except (RuntimeError, ValueError, OSError) as exc:
        print(f"STOP: {exc}", file=sys.stderr)
        return 2
    print("=== GATEWAY PRODUCTION IRADIOS-ONLY FINANCIAL AUDIT (READ ONLY) ===")
    print("Application: iradiosgr; application ID hidden")
    for name, value in counts.items():
        print(f"{name}: {value if value is not None else 'MISSING'}")
    print("=== PROVIDER / OUTBOX SAFETY GATES ===")
    for name, value in checks.items():
        print(f"{name}: {value if value is not None else 'MISSING'}")
    if blockers:
        print("BLOCKED: iRadios Production ledger is NOT cleared for a zero-test launch")
        for blocker in blockers:
            print(" - " + blocker)
        print("Do not delete provider transactions, refund proofs, or mandates.")
        return 1 if args.assert_clean else 0
    print("PASS: iRadios scoped operational financial rows are zero")
    print("NOTE: Nexi recurring permission, refunds and advertising financial UAT remain independent.")
    return 0

if __name__ == "__main__":
    sys.exit(main())
