#!/usr/bin/env python3
"""Read-only preflight for a zero-test-finance iRadios production launch.

This is NOT a deletion tool. Old provider payments and mandates must be
reconciled and archived before a new clean operational baseline is approved.
No email, customer identity, order ID, provider ID or secret is printed.
"""
import argparse
import re
import subprocess
import sys

PRODUCTION = "iluma_production"
TABLES = (
    "payments", "subscriptions", "recurring_mandates", "broadcaster_mandates",
    "saved_card_agreements", "recurring_cycles", "broadcaster_cycles",
    "billing_cycles", "nexi_contracts", "refunds", "outgoing_events",
    "payment_attempts", "advertising_budget_scopes",
    "broadcaster_boost_scopes", "winback_claims", "customers",
)
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
        raise RuntimeError("Gateway SQL audit failed (no credentials printed)")
    return [line.split("\t") for line in result.stdout.splitlines() if line.strip()]

def existing_tables():
    rows = query("SELECT TABLE_NAME FROM information_schema.TABLES "
                 "WHERE TABLE_SCHEMA='iluma_production' AND TABLE_TYPE='BASE TABLE';")
    return {row[0] for row in rows}

def count(table, sql=None):
    if not re.fullmatch(r"[a-z_]+", table):
        raise ValueError("Unexpected table")
    rows = query(sql or f"SELECT COUNT(*) FROM `{PRODUCTION}`.`{table}`;")
    return int(rows[0][0])

def audit():
    present = existing_tables()
    counts = {}
    for table in TABLES:
        counts[table] = count(table) if table in present else None
    checks = {
        "payment_unknown_or_open": count(
            "payments", "SELECT COUNT(*) FROM iluma_production.payments "
            "WHERE status IN ('unknown','pending','redirect','creating');"
        ) if "payments" in present else None,
        "unrefunded_paid_payments": count(
            "payments", "SELECT COUNT(*) FROM iluma_production.payments p "
            "WHERE p.status='paid' AND p.amount_cents > "
            "COALESCE((SELECT SUM(r.amount_cents) FROM iluma_production.refunds r "
            "WHERE r.payment_id=p.id AND r.confirmed_at IS NOT NULL),0);"
        ) if "payments" in present and "refunds" in present else None,
        "undelivered_events": count(
            "outgoing_events", "SELECT COUNT(*) FROM iluma_production.outgoing_events "
            "WHERE status <> 'delivered';"
        ) if "outgoing_events" in present else None,
        "live_listener_mandates": count(
            "recurring_mandates", "SELECT COUNT(*) FROM iluma_production.recurring_mandates "
            "WHERE status <> 'cancelled' OR deactivated_at IS NULL;"
        ) if "recurring_mandates" in present else None,
        "live_broadcaster_mandates": count(
            "broadcaster_mandates", "SELECT COUNT(*) FROM iluma_production.broadcaster_mandates "
            "WHERE status <> 'cancelled' OR deactivated_at IS NULL;"
        ) if "broadcaster_mandates" in present else None,
    }
    blockers = [f"{k}: {counts[k] if counts[k] is not None else 'MISSING'}"
                for k in REQUIRED_ZERO if counts[k] is None or counts[k] != 0]
    blockers += [f"{k}: {v if v is not None else 'MISSING'}"
                 for k,v in checks.items() if v is None or v > 0]
    return counts, checks, blockers

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--assert-clean", action="store_true",
                        help="Exit nonzero unless the new production financial ledger is empty")
    args = parser.parse_args()
    try:
        counts, checks, blockers = audit()
    except (RuntimeError, ValueError, OSError) as exc:
        print(f"STOP: {exc}", file=sys.stderr)
        return 2
    print("=== GATEWAY PRODUCTION CLEAN-LAUNCH AUDIT (READ ONLY) ===")
    for name,value in counts.items():
        print(f"{name}: {value if value is not None else 'MISSING'}")
    print("=== PROVIDER / OUTBOX SAFETY GATES ===")
    for name,value in checks.items():
        print(f"{name}: {value if value is not None else 'MISSING'}")
    if blockers:
        print("BLOCKED: Production is NOT an empty launch ledger.")
        for blocker in blockers: print(" - "+blocker)
        print("Do not delete/zero provider ledgers. Reconcile Nexi, refunds, "
              "mandates and pinned webhook deliveries first.")
        return 1 if args.assert_clean else 0
    print("PASS: No active or historical operational financial rows remain.")
    print("NOTE: Counts do not establish Nexi MIT merchant permission or advertising UAT.")
    return 0

if __name__ == "__main__":
    sys.exit(main())
