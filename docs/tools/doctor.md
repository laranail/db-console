# doctor

Health-check every server and report the security posture.

## Overview

`doctor` probes each registered server for reachability, TLS posture, the real capability set, and — the doctrine-not-option check — whether the admin account is **root-like** (`ALL PRIVILEGES ON *.*` or `SUPER`), in which case it fails with the exact minimal-grant fix so setup cannot quietly proceed insecure. It also reports the active secret driver (warning on `app_key`) and the catalog encryption mode. A root-like admin raises a `SuspiciousActivity` alert. `doctor` runs as the last step of install.

---

[← Docs index](../../README.md#documentation)
