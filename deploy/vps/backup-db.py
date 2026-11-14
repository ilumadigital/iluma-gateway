#!/usr/bin/env python3
"""Backup three VPS Gateway tenants, read-only; no Nexi calls or mutations.

Output is sensitive financial/identity data! Root-only local backup is a
short-lived staging artifact: encrypt/offload and enforce retention separately.
"""
import gzip
import hashlib
import os
from pathlib import Path
import subprocess
import sys
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parents[2]
BACKUP = Path("/root/gateway-migration/backups")
DBS = ("iluma_registry", "iluma_staging", "iluma_production")


def main():
    if os.geteuid() != 0 or len(sys.argv) != 1:
        raise ValueError("Usage: sudo python3 deploy/vps/backup-db.py")
    BACKUP.mkdir(parents=True, mode=0o700, exist_ok=True)
    os.chmod(BACKUP, 0o700)
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    for db in DBS:
        target = BACKUP / f"{db}-{stamp}.sql.gz"
        command = [
            "docker", "compose", "-f", str(ROOT / "compose.vps.yml"),
            "exec", "-T", "db", "sh", "-c",
            'MYSQL_PWD="$(cat /run/secrets/mysql_root_password)" exec mysqldump --single-transaction --quick --routines --triggers --events --no-tablespaces --set-gtid-purged=OFF -uroot --protocol=socket "$1"', "sh", db
        ]
        fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "wb") as destination:
            with gzip.GzipFile(fileobj=destination, mode="wb", mtime=0) as zipped:
                process = subprocess.Popen(
                    command, cwd=ROOT, stdout=subprocess.PIPE,
                    stderr=subprocess.DEVNULL
                )
                assert process.stdout is not None
                while True:
                    data = process.stdout.read(1024 * 1024)
                    if not data:
                        break
                    zipped.write(data)
                process.stdout.close()
                code = process.wait()
        if code != 0 or target.stat().st_size < 100:
            target.unlink(missing_ok=True)
            raise RuntimeError(f"Backup failed for {db}; no further tenants attempted")
        digest = hashlib.sha256()
        with target.open("rb") as file:
            while chunk := file.read(1024 * 1024):
                digest.update(chunk)
        print(f"PASS: {db} compressed bytes={target.stat().st_size} sha256={digest.hexdigest()}")
    print("Private files saved under /root/gateway-migration/backups (mode 0600).")
    print("Encrypt and copy backups + original MASTER_KEY_B64 off-server securely.")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"STOP: {exc}", file=sys.stderr)
        sys.exit(1)
