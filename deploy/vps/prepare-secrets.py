#!/usr/bin/env python3
"""One-time VPS secrets preparation. Never uploads or prints private values.

Run as root on VPS after transferring the original encrypted-credential ENV
file through SSH into root-only storage outside the web/Git tree.
"""
import base64
import os
from pathlib import Path
import re
import secrets
import stat
import sys

DEST = Path("/etc/iluma-gateway")
SOURCE_KEYS = (
    "MASTER_KEY_B64", "NEXI_STAGING_BASE", "NEXI_PRODUCTION_BASE",
    "IRADIOS_STAGING_WEBHOOK", "IRADIOS_PRODUCTION_WEBHOOK",
)
EXPECTED = {
    "NEXI_STAGING_BASE": "https://xpaysandbox.nexigroup.com/api/phoenix-0.0/psp",
    "NEXI_PRODUCTION_BASE": "https://xpay.nexigroup.com/api/phoenix-0.0/psp",
    "IRADIOS_STAGING_WEBHOOK": "https://stage.iradios.gr/api/gateway/webhook",
    "IRADIOS_PRODUCTION_WEBHOOK": "https://play.iradios.gr/api/gateway/webhook",
}
TABLES = (("REGISTRY", "iluma_registry", "iluma_registry_app"),
          ("STAGING", "iluma_staging", "iluma_staging_app"),
          ("PRODUCTION", "iluma_production", "iluma_production_app"))


def read_old(path: Path) -> dict:
    if path.is_symlink() or not path.is_file():
        raise ValueError("Original private ENV must be a regular file")
    if stat.S_IMODE(path.stat().st_mode) & 0o077:
        raise ValueError("Original private ENV must be chmod 600")
    if path.stat().st_uid != 0:
        raise ValueError("Original private ENV must be owned by root on VPS")
    data = {}
    for n, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        match = re.fullmatch(r"([A-Z][A-Z0-9_]*)\s*=\s*(.*)", line)
        if not match:
            raise ValueError(f"Malformed private ENV at line {n}")
        key, value = match.groups()
        if key in data:
            raise ValueError(f"Duplicate private ENV key at line {n}")
        value = value.strip()
        if len(value) >= 2 and value[0] in ("'", '"') and value[-1] == value[0]:
            value = value[1:-1]
        if "\n" in value or "\r" in value or "\x00" in value:
            raise ValueError("Invalid private ENV value")
        data[key] = value
    return data


def write_new(path: Path, content: bytes, owner: int, group: int) -> None:
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, "wb") as handle:
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.chown(path, owner, group)
        os.chmod(path, 0o600)
    except Exception:
        path.unlink(missing_ok=True)
        raise


def main() -> None:
    if os.geteuid() != 0 or len(sys.argv) != 2:
        raise ValueError("Usage: sudo python3 deploy/vps/prepare-secrets.py /root/secure/old-gateway.env")
    source = Path(sys.argv[1]).resolve(strict=True)
    if "/srv/iluma-gateway/app" in str(source) or "/public_html/" in str(source):
        raise ValueError("Never load private credentials from application webroot")
    old = read_old(source)
    for key in SOURCE_KEYS:
        if not old.get(key):
            raise ValueError(f"Missing required original setting: {key}")
    if len(base64.b64decode(old["MASTER_KEY_B64"], validate=True)) != 32:
        raise ValueError("Original MASTER_KEY_B64 is not 32 bytes; DO NOT create a new one")
    for key, value in EXPECTED.items():
        if old[key] != value:
            raise ValueError(f"Unexpected {key}; review configuration before transfer")
    if DEST.is_symlink():
        raise ValueError("Secret directory must not be a symlink")
    DEST.mkdir(mode=0o700, exist_ok=True)
    os.chown(DEST, 0, 0)
    os.chmod(DEST, 0o700)
    private_env = DEST / "iluma-gateway.env"
    root_secret = DEST / "mysql-root.password"
    if private_env.exists() or root_secret.exists():
        raise ValueError("Secrets already exist: refusing to overwrite or rotate keys")

    pairs = [
        ("APP_ENV", "development"),
        ("GATEWAY_HOST", "gateway.iluma.gr"),
        ("PRODUCTION_ENABLED", "0"),
        ("MASTER_KEY_B64", old["MASTER_KEY_B64"]),
    ]
    for key in SOURCE_KEYS[1:]:
        pairs.append((key, old[key]))
    for prefix, dbname, username in TABLES:
        pairs.extend([
            (prefix + "_DSN", f"mysql:host=db;port=3306;dbname={dbname};charset=utf8mb4"),
            (prefix + "_USER", username),
            (prefix + "_PASSWORD", secrets.token_hex(32)),
        ])
    # No off-session charges during migration, regardless of old runtime flags.
    for key in (
        "MIT_SANDBOX_EXECUTION_ENABLED", "MIT_SANDBOX_UAT_APPROVED",
        "BROADCASTER_MIT_SANDBOX_EXECUTION_ENABLED",
        "BROADCASTER_MIT_SANDBOX_UAT_APPROVED", "ADVERTISING_SANDBOX_ENABLED",
    ):
        pairs.append((key, "0"))

    content = "".join(f"{k}={v}\n" for k, v in pairs).encode()
    try:
        write_new(root_secret, (secrets.token_hex(32) + "\n").encode(), 0, 0)
        # Container's PHP-FPM and worker run as uid/gid 33 (www-data) and
        # can read this 0600 file. Host root can always back it up.
        write_new(private_env, content, 33, 33)
    except Exception:
        private_env.unlink(missing_ok=True)
        root_secret.unlink(missing_ok=True)
        raise
    print("PASS: created private VPS config and MySQL root secret (no values displayed)")
    print("PASS: preserved original MASTER_KEY_B64; all production/MIT flags locked")
    print("NEXT: build Compose stack, provision DB users, import reviewed SQL exports")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"STOP: {exc}", file=sys.stderr)
        sys.exit(1)
