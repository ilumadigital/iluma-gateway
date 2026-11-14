#!/usr/bin/env python3
"""Offline migration fixture tests: no Docker, database or credentials."""
import gzip
import importlib.util
from pathlib import Path
import tarfile
import tempfile
import zipfile

source = Path(__file__).resolve().parents[1] / "deploy/vps/import-db.py"
spec = importlib.util.spec_from_file_location("iluma_vps_import", source)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

sample = b"-- phpMyAdmin export\nCREATE TABLE payments (id INT PRIMARY KEY);\nINSERT INTO payments VALUES(1);\n"
with tempfile.TemporaryDirectory(prefix="iluma-import-ci-") as directory:
    root = Path(directory)
    sql = root / "data.sql"
    sql.write_bytes(sample)
    gzip_path = root / "data.sql.gz"
    with gzip.open(gzip_path, "wb") as handle:
        handle.write(sample)
    zip_path = root / "data.zip"
    with zipfile.ZipFile(zip_path, "w") as handle:
        handle.writestr("data.sql", sample)
    tar_path = root / "data.tar.gz"
    with tarfile.open(tar_path, "w:gz") as handle:
        handle.add(sql, arcname="data.sql")

    for file_path in (sql, gzip_path, zip_path, tar_path):
        output = root / "unpacked.sql"
        size, digest = module.extract_stream(file_path, output)
        assert size == len(sample) and output.read_bytes() == sample
        assert len(digest) == 64
        output.unlink()
        print("PASS: examined", file_path.suffix)

    with zipfile.ZipFile(zip_path, "w") as handle:
        handle.writestr("data.sql", sample)
        handle.writestr("unexpected.php", "bad")
    try:
        module.extract_stream(zip_path, root / "wrong.sql")
        raise AssertionError("unsafe extra archive member accepted")
    except ValueError:
        pass

    assert module.BLOCK.search(b"USE other_database;\n")
    assert module.BLOCK.search(b"CREATE USER 'attacker'@'%';\n")
    assert module.BLOCK.search(b"DROP DATABASE iluma_registry;\n")
    assert not module.BLOCK.search(sample)
    assert module.CREATE.search(sample)
print("PASS: SQL migration import safety fixture tests")
