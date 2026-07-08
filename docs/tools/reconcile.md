# Reconcile

Report drift between the catalog and the live server — never auto-mutate.

## Overview

`reconcile` diffs the catalog against the live server and reports: **orphans** (catalog rows whose objects no longer exist) and **unmanaged** objects (live objects with no catalog row), for both databases and accounts. It **never mutates the server** to force a match — silently 'fixing' production is how outages happen. With `--adopt` it pulls unmanaged objects into the catalog (marked not-managed-by-DBConsole), still without touching the server. Drift raises a `ReconcileDriftFound` event.

---

[← Docs index](../../README.md#documentation)
