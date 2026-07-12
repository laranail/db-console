# Secret vault

Four secret drivers behind one seam; secrets redact themselves everywhere.

## Overview

`SecretVaultManager` resolves one of four drivers from `secrets.driver`:

| Driver | Storage |
|---|---|
| `app_key` | Encrypted with the Laravel app key (canonical; blocked in production without an override). |
| `kms` | AES-256-GCM envelope encryption with a cloud KMS data key (AWS/GCP). |
| `vault` | HashiCorp Vault KV v2 over HTTP. |
| `reference` | A pointer to a secret resolved elsewhere (env, file, external resolver). |

Secrets are wrapped in a `Secret`/`Password` value object that redacts itself in `__toString`, `__debugInfo`, and JSON — so a secret can never appear in a log, an exception, the audit trail, or a webhook. `secrets:rotate` re-wraps every stored secret under the driver's new key material without changing the value.

---

[← Docs index](../../README.md#documentation)
