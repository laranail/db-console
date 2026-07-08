# Wizard & rollback

The full provision flow with compensating rollback.

## Overview

`WizardExecutor` runs a sequence of steps (create database → create account → grant) and, if a later step fails, undoes the earlier steps it created — in reverse. Rollback is **compensating and safe**: it drops a database only if this run created it and it is still empty; it never touches pre-existing data. If a rollback step itself fails, DBConsole escalates with a `RollbackFailed` event and a critical alert rather than leaving a silent mess. `ProvisioningWizard` is the ready-made database+account+grant flow.

---

[← Docs index](../../README.md#documentation)
