"""ATLAS V5 P090: deterministic CSV-manifest quality gate, no database writes.

Runtime is intentionally standard-library-only. Airflow calls these functions
through the official Airflow 3 Task SDK DAG. Input and output dirs are
configured by the local Airflow Compose profile, not provided by API clients.
"""
from __future__ import annotations

import csv
import hashlib
import json
import os
from pathlib import Path
import re
import tempfile

KEY = re.compile(r"^[A-Z0-9][A-Z0-9_-]{1,59}$")
SHA256 = re.compile(r"^[a-f0-9]{64}$")
COLUMNS = ("external_id", "amount_cents")


class ManifestValidationError(ValueError):
    pass


def safe_file(base: Path, name: str) -> Path:
    if not isinstance(name, str) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,119}", name):
        raise ManifestValidationError("Filename must be a basename without traversal.")
    if name.startswith(".") or ".." in name:
        raise ManifestValidationError("Hidden or traversal filenames are not permitted.")
    root = base.resolve(strict=True)
    candidate = root / name
    if candidate.is_symlink() or not candidate.is_file():
        raise ManifestValidationError("Expected a regular non-symlink input file.")
    if candidate.resolve().parent != root:
        raise ManifestValidationError("Input file escapes its source directory.")
    return candidate


def validate_batch(input_dir: str, manifest_file: str) -> dict:
    """Validate bounded bytes, declared rows/hash and canonical V5 CSV columns."""
    base = Path(input_dir)
    manifest_path = safe_file(base, manifest_file)
    if manifest_path.stat().st_size > 8192:
        raise ManifestValidationError("Manifest size exceeds the 8 KB limit.")
    try:
        spec = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (ValueError, UnicodeError) as exc:
        raise ManifestValidationError("Manifest must be valid UTF-8 JSON.") from exc
    if not isinstance(spec, dict) or set(spec) != {
        "version", "company_code", "source_code", "batch_key",
        "file_name", "sha256", "expected_rows",
    }:
        raise ManifestValidationError("Unknown or missing manifest fields.")
    if spec["version"] != 1:
        raise ManifestValidationError("Unsupported manifest version.")
    for key in ("company_code", "source_code", "batch_key"):
        if not isinstance(spec[key], str) or not KEY.fullmatch(spec[key]):
            raise ManifestValidationError("Invalid manifest key: " + key)
    if not isinstance(spec["sha256"], str) or not SHA256.fullmatch(spec["sha256"]):
        raise ManifestValidationError("Invalid sha256.")
    if type(spec["expected_rows"]) is not int or not 0 <= spec["expected_rows"] <= 20000:
        raise ManifestValidationError("Invalid expected_rows.")
    data_path = safe_file(base, spec["file_name"])
    if data_path.suffix != ".csv" or data_path.stat().st_size > 5_000_000:
        raise ManifestValidationError("CSV file invalid or exceeds 5 MB.")

    raw = data_path.read_bytes()
    if hashlib.sha256(raw).hexdigest() != spec["sha256"]:
        raise ManifestValidationError("CSV digest mismatch.")
    try:
        text = raw.decode("utf-8-sig")
        import io
        reader = csv.DictReader(io.StringIO(text, newline=""), strict=True)
        if tuple(reader.fieldnames or []) != COLUMNS:
            raise ManifestValidationError("Expected canonical external_id,amount_cents CSV columns.")
        count = 0
        seen = set()
        for row in reader:
            if count >= 20000:
                raise ManifestValidationError("Row safety limit exceeded.")
            if None in row or set(row) != set(COLUMNS):
                raise ManifestValidationError("Unexpected CSV columns.")
            external = row["external_id"]
            if not external or len(external) > 120 or external in seen:
                raise ManifestValidationError("Missing/duplicate external_id.")
            seen.add(external)
            cents = row["amount_cents"]
            if not isinstance(cents, str) or not re.fullmatch(r"(0|[1-9][0-9]{0,11})", cents):
                raise ManifestValidationError("Invalid nonnegative amount_cents.")
            count += 1
    except (csv.Error, UnicodeError) as exc:
        raise ManifestValidationError("Invalid CSV encoding or structure.") from exc
    if count != spec["expected_rows"]:
        raise ManifestValidationError("CSV row count mismatch.")
    return {
        "version": 1,
        "status": "validated",
        "company_code": spec["company_code"],
        "source_code": spec["source_code"],
        "batch_key": spec["batch_key"],
        "sha256": spec["sha256"],
        "row_count": count,
        "input_file": spec["file_name"],
    }


def save_report(report_dir: str, result: dict) -> str:
    """Stable output filename based on tenant/source/batch and CSV digest."""
    base = Path(report_dir)
    base.mkdir(parents=True, exist_ok=True)
    parts = (result["company_code"], result["source_code"], result["batch_key"])
    if any(not KEY.fullmatch(str(key)) for key in parts):
        raise ManifestValidationError("Unexpected report identifiers.")
    if not SHA256.fullmatch(str(result["sha256"])):
        raise ManifestValidationError("Unexpected report digest.")
    basename = "_".join(parts) + "_" + result["sha256"][:16] + ".json"
    destination = base / basename
    payload = json.dumps(result, ensure_ascii=True, sort_keys=True, indent=2) + "\n"
    if destination.exists():
        if destination.is_symlink() or destination.read_text(encoding="utf-8") != payload:
            raise ManifestValidationError("Existing batch report differs: human review required.")
        return str(destination)
    descriptor, tmpname = tempfile.mkstemp(prefix="atlas_", suffix=".tmp", dir=base)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as f:
            f.write(payload)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmpname, destination)
    finally:
        if os.path.exists(tmpname):
            os.unlink(tmpname)
    return str(destination)
