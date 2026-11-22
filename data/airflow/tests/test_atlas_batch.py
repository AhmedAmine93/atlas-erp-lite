"""Standard-library tests; no Airflow installation or production credentials."""
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from atlas_batch import ManifestValidationError, validate_batch, save_report


class AtlasBatchQualityTests(unittest.TestCase):
    def setUp(self):
        self.work = tempfile.TemporaryDirectory()
        self.addCleanup(self.work.cleanup)
        self.base = Path(self.work.name)
        self.csv_file = self.base / "sample.csv"
        self.csv_file.write_text("external_id,amount_cents\nA-01,100\nA-02,0\n", encoding="utf-8")
        self.manifest = {
            "version": 1, "company_code": "ATLAS_DEMO",
            "source_code": "CSV_01", "batch_key": "TEST_B1",
            "file_name": "sample.csv",
            "sha256": hashlib.sha256(self.csv_file.read_bytes()).hexdigest(),
            "expected_rows": 2,
        }
        self.write_manifest()

    def write_manifest(self):
        (self.base / "manifest.json").write_text(json.dumps(self.manifest), encoding="utf-8")

    def test_valid_manifest_and_repeat_safe_report(self):
        result = validate_batch(str(self.base), "manifest.json")
        self.assertEqual(result["status"], "validated")
        self.assertEqual(result["row_count"], 2)
        reportdir = str(self.base / "out")
        first = save_report(reportdir, result)
        second = save_report(reportdir, result)
        self.assertEqual(first, second)
        self.assertEqual(len(list((self.base / "out").glob("*.json"))), 1)

    def test_digest_tamper_and_row_count_rejected(self):
        self.manifest["sha256"] = "0" * 64
        self.write_manifest()
        with self.assertRaisesRegex(ManifestValidationError, "digest"):
            validate_batch(str(self.base), "manifest.json")
        self.manifest["sha256"] = hashlib.sha256(self.csv_file.read_bytes()).hexdigest()
        self.manifest["expected_rows"] = 3
        self.write_manifest()
        with self.assertRaisesRegex(ManifestValidationError, "count"):
            validate_batch(str(self.base), "manifest.json")

    def test_duplicate_external_ids_rejected(self):
        self.csv_file.write_text("external_id,amount_cents\nA-01,100\nA-01,200\n", encoding="utf-8")
        self.manifest["sha256"] = hashlib.sha256(self.csv_file.read_bytes()).hexdigest()
        self.write_manifest()
        with self.assertRaisesRegex(ManifestValidationError, "duplicate"):
            validate_batch(str(self.base), "manifest.json")

    def test_wrong_schema_and_negative_amount_rejected(self):
        self.csv_file.write_text("external_id,amount_cents\nA-01,-10\n", encoding="utf-8")
        self.manifest["sha256"] = hashlib.sha256(self.csv_file.read_bytes()).hexdigest()
        self.manifest["expected_rows"] = 1
        self.write_manifest()
        with self.assertRaisesRegex(ManifestValidationError, "amount"):
            validate_batch(str(self.base), "manifest.json")

    def test_traversal_and_symlink_rejected(self):
        self.manifest["file_name"] = "../outside.csv"
        self.write_manifest()
        with self.assertRaisesRegex(ManifestValidationError, "Filename"):
            validate_batch(str(self.base), "manifest.json")
        self.manifest["file_name"] = "sample.csv"
        self.write_manifest()
        with self.assertRaisesRegex(ManifestValidationError, "Filename"):
            validate_batch(str(self.base), "../manifest.json")

    def test_existing_report_conflict_is_not_silently_overwritten(self):
        result = validate_batch(str(self.base), "manifest.json")
        path = Path(save_report(str(self.base / "out"), result))
        path.write_text('{"tampered":true}', encoding="utf-8")
        with self.assertRaisesRegex(ManifestValidationError, "differs"):
            save_report(str(self.base / "out"), result)


if __name__ == "__main__":
    unittest.main()
