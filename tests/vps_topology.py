#!/usr/bin/env python3
"""Read-only topology assertions: Gateway must never join or mutate iRadios."""
import json
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[1]
command = ["docker", "compose", "-f", str(root / "compose.vps.yml"),
           "config", "--format", "json"]
result = subprocess.run(command, check=True, capture_output=True, text=True, cwd=root)
config = json.loads(result.stdout)
services = config["services"]
assert config["name"] == "iluma_gateway"
assert set(services) == {"db", "php", "web", "worker"}
assert not services["db"].get("ports"), "MySQL must have no host ports"
assert not services["php"].get("ports"), "PHP-FPM must have no host ports"
assert not services["worker"].get("ports"), "Worker must have no host ports"
assert services["worker"].get("profiles") == ["worker"], "Worker must be off by default"
assert services["php"].get("read_only") is True
assert services["worker"].get("read_only") is True
for name in ("php", "worker"):
    assert "ALL" in services[name].get("cap_drop", [])
    mounts = services[name]["volumes"]
    assert any(x.get("source") == "/etc/iluma-gateway/iluma-gateway.env"
               and x.get("read_only") is True for x in mounts)
ports = services["web"]["ports"]
assert len(ports) == 1
assert ports[0]["host_ip"] == "127.0.0.1"
assert str(ports[0]["published"]) == "5561"
assert int(ports[0]["target"]) == 8080
assert not any("iradios_stage" in network for network in config.get("networks", {}))
assert "gateway_mysql_data" in config["volumes"]
print("PASS: isolated Gateway topology, immutable PHP and opt-in staging worker")
