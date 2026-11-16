#!/usr/bin/env python3
"""Offline refusal tests; no database, provider, secrets or Docker."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("production_billing", ROOT / "deploy/vps/production-billing.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
manifest = json.loads(module.MANIFEST.read_text())


class PreparationTests(unittest.TestCase):
    def test_catalog_is_exact_and_conflicts_are_not_overwritten(self):
        self.assertEqual(len(manifest["catalog"]), 17)
        self.assertEqual(manifest["catalog"]["B-PREM"], 9900)
        self.assertEqual(manifest["catalog"]["ADD-DSP"], 2790)
        missing, conflicts = module.catalog_plan(manifest["catalog"], {
            "B-PREM": (11900, "EUR", 1), "ADD-DSP": (2790, "EUR", 1),
            "ADD-AN": (690, "USD", 1), "ADD-ADS": (890, "EUR", 0)})
        self.assertNotIn("ADD-DSP", missing)
        self.assertEqual(set(conflicts), {"catalog-conflict:B-PREM", "catalog-conflict:ADD-AN",
                                          "catalog-conflict:ADD-ADS"})
        self.assertIn("L-PREM", missing)

    def test_only_the_eight_expected_create_statements_are_accepted(self):
        self.assertEqual(set(module.migration_statements()), set(manifest["tables"]))
        original = module.DDL
        try:
            with tempfile.TemporaryDirectory() as directory:
                module.DDL = Path(directory) / "invalid.sql"
                module.DDL.write_text(original.read_text() + "\nDROP TABLE payments;")
                with self.assertRaises(ValueError):
                    module.migration_statements()
        finally:
            module.DDL = original

    def test_input_cannot_select_another_database_or_inject_sql(self):
        for value in ["a';DROP DATABASE x;--", "x\nUSE iluma_staging", "a b"]:
            with self.assertRaises(ValueError):
                module.quote(value)
        self.assertEqual(module.normalize_type("INT(11) unsigned"), "int unsigned")

    def test_refusals_happen_before_backup_or_ddl(self):
        original_audit, original_backup = module.audit, module.verified_backup
        plan = dict(application_id=1, missing_tables=["recurring_mandates"],
                    missing_columns=[], missing_index=False, missing_products=[],
                    missing_dml=["INSERT"], occupied_tables=[], blockers=[])
        try:
            module.audit = lambda _: plan
            module.verified_backup = lambda: self.fail("backup/write reached before refusal")
            with self.assertRaises(ValueError):
                module.apply(manifest, False)
            plan["occupied_tables"] = ["payments"]
            with self.assertRaises(ValueError):
                module.apply(manifest, True)
            plan["occupied_tables"] = []
            plan["blockers"] = ["catalog-conflict:B-PREM"]
            with self.assertRaises(ValueError):
                module.apply(manifest, True)
        finally:
            module.audit, module.verified_backup = original_audit, original_backup


if __name__ == "__main__":
    unittest.main()
