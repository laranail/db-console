# Attach / detach

Grant or revoke one account across many databases in one audited batch.

## Overview

`PrivilegeManager::attach`/`detach` apply a privilege set to one account across a list of databases as a single batch. Each pairing is authorized and audited individually, and the `BatchResult` reports per-pairing success and failure — a partial failure does not roll back the successes, but every outcome is recorded. `user:edit --new-host` performs a grant-preserving host change: it reads the existing grants, recreates the account on the new host (preserving the authentication), re-applies the grants, and drops the old host only after the new one is verified.

---

[← Docs index](../../README.md#documentation)
