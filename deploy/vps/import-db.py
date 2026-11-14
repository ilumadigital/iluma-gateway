#!/usr/bin/env python3
"""Safely inspect/import one trusted phpMyAdmin SQL export into one EMPTY Gateway DB.

Inspect mode never writes DB. Apply requires matching SHA256; no implicit DROP,
CREATE/USE DATABASE, privileges or account SQL allowed. ZIP/TAR archives
must each contain exactly ONE .sql member; nothing is extracted in webroot.
"""
import argparse
import gzip
import hashlib
import os
from pathlib import Path
import re
import subprocess
import sys
import tarfile
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[2]
DBS = {"iluma_registry", "iluma_staging", "iluma_production"}
BLOCK = re.compile(rb"(?im)^\s*(?:CREATE|DROP|ALTER)\s+DATABASE\b|^\s*USE\s+|^\s*(?:CREATE|ALTER|DROP)\s+USER\b|^\s*(?:GRANT|REVOKE|SET\s+GLOBAL|FLUSH\s+PRIVILEGES)\b")
CREATE = re.compile(rb"(?im)^\\s*CREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?`?([A-Za-z0-9_]+)`?")
CORE = {
    "iluma_registry": {"applications", "application_credentials", "nexi_credentials", "admin_users"},
    "iluma_staging": {"customers", "products", "payments", "outgoing_events", "event_deliveries"},
    "iluma_production": {"customers", "products", "payments"},
}
MAX_SQL = 25 * 1024 * 1024 * 1024


def extract_stream(path, output):
    name = path.name.lower()
    if name.endswith(".sql"):
        source = path.open("rb")
        package = None
    elif name.endswith(".sql.gz"):
        source = gzip.open(path, "rb")
        package = None
    elif name.endswith(".zip"):
        package = zipfile.ZipFile(path)
        members = [x for x in package.infolist() if not x.is_dir()]
        if len(members) != 1 or not members[0].filename.lower().endswith(".sql"):
            raise ValueError("ZIP must contain one SQL member and no other files")
        source = package.open(members[0])
    elif name.endswith((".tar.gz", ".tgz")):
        package = tarfile.open(path, "r:gz")
        members = package.getmembers()
        if len(members) != 1 or not members[0].isfile() or not members[0].name.lower().endswith(".sql"):
            raise ValueError("TAR.GZ must contain exactly one regular SQL member")
        source = package.extractfile(members[0])
        if source is None:
            raise ValueError("SQL member unreadable")
    else:
        raise ValueError("Supported extensions: .sql, .sql.gz, .zip, .tar.gz, .tgz")
    size = 0
    digest = hashlib.sha256()
    try:
        with source, output.open("wb") as target:
            os.chmod(output, 0o600)
            while True:
                block = source.read(1024 * 1024)
                if not block:
                    break
                size += len(block)
                if size > MAX_SQL:
                    raise ValueError("Expanded export too large; aborting")
                digest.update(block)
                target.write(block)
    finally:
        if package is not None:
            package.close()
    return size, digest.hexdigest()


def mysql(statement):
    command = ["docker", "compose", "-f", str(ROOT / "compose.vps.yml"),
               "exec", "-T", "db", "sh", "-c",
               'MYSQL_PWD="$(cat /run/secrets/mysql_root_password)" exec mysql --protocol=socket -uroot -N -B']
    return subprocess.run(command, input=statement.encode(), capture_output=True, cwd=ROOT)


def apply_dump(database, path):
    command = ["docker", "compose", "-f", str(ROOT / "compose.vps.yml"),
               "exec", "-T", "db", "sh", "-c",
               'MYSQL_PWD="$(cat /run/secrets/mysql_root_password)" exec mysql --binary-mode=1 --default-character-set=utf8mb4 --protocol=socket -uroot "$1"', "sh", database]
    with path.open("rb") as source:
        result = subprocess.run(command, stdin=source, stdout=subprocess.DEVNULL,
                                stderr=subprocess.PIPE, cwd=ROOT)
    return result.returncode


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("database", choices=sorted(DBS))
    parser.add_argument("archive", type=Path)
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--sha256", default="")
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise ValueError("Run as root; source exports are private")
    path = args.archive.resolve(strict=True)
    if not path.is_file() or str(path).startswith("/srv/iluma-gateway/app/"):
        raise ValueError("SQL export must be a regular file outside source repository")
    with tempfile.TemporaryDirectory(prefix="iluma-import-", dir="/root") as folder:
        plain = Path(folder) / "export.sql"
        size, checksum = extract_stream(path, plain)
        if size < 100:
            raise ValueError("Invalid or empty SQL export")
        tables = set()
        with plain.open("rb") as source:
            for line in source:
                if len(line) > 64 * 1024 * 1024:
                    raise ValueError("SQL line exceeds safety threshold")
                if BLOCK.search(line):
                    raise ValueError("Database/privilege-changing SQL detected; inspect export manually")
                match = CREATE.search(line)
                if match:
                    tables.add(match.group(1).decode("ascii").lower())
        print(f"Database: {args.database}")
        print(f"Plaintext bytes: {size}")
        print(f"Export SHA256: {checksum}")
        print(f"CREATE TABLE entries: {len(tables)}")
        print(f"Expected core tables present: {len(CORE[args.database] & tables)}/{len(CORE[args.database])}")
        missing = CORE[args.database] - tables
        if missing:
            raise ValueError("Export is missing required tenant tables: " + ", ".join(sorted(missing)))
        if not args.apply:
            print("INSPECT ONLY. Database remains unchanged.")
            return
        if args.sha256 != checksum:
            raise ValueError("Import requires the exact SHA256 printed during inspection")
        query = f"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{args.database}';"
        status = mysql(query)
        if status.returncode or status.stdout.strip() != b"0":
            raise ValueError("Target database must exist and contain ZERO tables")
        if apply_dump(args.database, plain):
            raise RuntimeError("Import failed; database may now be partially populated. Do NOT rerun blindly.")
        status = mysql(query)
        if status.returncode or int(status.stdout.strip()) < len(tables):
            raise RuntimeError("Imported table count mismatch; investigate privately")
        print("PASS: import into empty tenant; verify payment totals/outbox before enabling worker")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print(f"STOP: {error}", file=sys.stderr)
        sys.exit(1)
