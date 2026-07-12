# Audit trail

An append-only, tamper-evident record of every action.

## Overview

Every domain event is written to the audit log with its actor, server, target, and outcome. The trail is **append-only** — an observer blocks updates and deletes — and **hash-chained**: each row's hash incorporates the previous row's, anchored to a genesis hash. `audit:verify` walks the chain and reports the exact row where it breaks, so tampering is detectable. Secrets never appear in the trail (they redact themselves), and the exception translator never copies a `QueryException` message, because those embed SQL with credentials.

---

[← Docs index](../../README.md#documentation)
