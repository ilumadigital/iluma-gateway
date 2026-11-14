#!/usr/bin/env python3
"""Provision exactly three Gateway databases with scoped, isolated credentials.

Reads the existing private config, sends SQL through stdin to mysql via Docker.
Never prints or persists plaintext passwords or performs imports.
"""
import os
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
CONF = Path("/etc/iluma-gateway/iluma-gateway.env")
SCOPE = (
    ("REGISTRY", "iluma_registry", "iluma_registry_app",
     "SELECT, INSERT, UPDATE, DELETE"),
    ("STAGING", "iluma_staging", "iluma_staging_app",
     "SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES"),
    ("PRODUCTION", "iluma_production", "iluma_production_app", "SELECT"),
)


def main() -> None:
    if os.geteuid() != 0 or len(sys.argv) != 1:
        raise ValueError("Usage: sudo python3 deploy/vps/provision-db.py")
    if not CONF.is_file() or CONF.is_symlink() or CONF.stat().st_mode & 0o077:
        raise ValueError("Private Gateway ENV absent or insecure")
    env = {}
    for line in CONF.read_text().splitlines():
        if "=" in line and not line.startswith("#"):
            key, val = line.split("=", 1)
            env[key] = val
    if env.get("PRODUCTION_ENABLED") != "0":
        raise ValueError("PRODUCTION_ENABLED must remain 0")
    script = ["SET sql_notes=0;"]
    for prefix, db, username, grants in SCOPE:
        if env.get(prefix + "_USER") != username:
            raise ValueError(f"Unexpected {prefix}_USER")
        pwd = env.get(prefix + "_PASSWORD", "")
        if not re.fullmatch(r"[a-f0-9]{64}", pwd):
            raise ValueError(f"Invalid generated {prefix}_PASSWORD")
        if env.get(prefix + "_DSN") != (
            f"mysql:host=db;port=3306;dbname={db};charset=utf8mb4"
        ):
            raise ValueError(f"Unexpected {prefix}_DSN")
        script.extend([
            f"CREATE DATABASE IF NOT EXISTS {db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
            f"CREATE USER IF NOT EXISTS '{username}'@'%' IDENTIFIED BY '{pwd}';",
            f"ALTER USER '{username}'@'%' IDENTIFIED BY '{pwd}';",
            f"GRANT {grants} ON {db}.* TO '{username}'@'%';",
        ])
    command = [
        "docker", "compose", "-f", str(ROOT / "compose.vps.yml"),
        "exec", "-T", "db", "sh", "-c",
        'MYSQL_PWD="$(cat /run/secrets/mysql_root_password)" exec mysql --protocol=socket -uroot'
    ]
    result = subprocess.run(command, cwd=ROOT, input=("\n".join(script) + "\n").encode(), capture_output=True)
    if result.returncode:
        match = re.search(rb"ERROR\s+(\d{3,5})", result.stderr)
        error_code = match.group(1).decode("ascii") if match else "unknown"
        raise RuntimeError(f"MySQL provisioning failed (exit={result.returncode}, mysql_code={error_code})")
    print("PASS: three isolated databases and least-privilege app users provisioned")
    print("Production application user: SELECT-only; production charges remain locked")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"STOP: {exc}", file=sys.stderr)
        sys.exit(1)
