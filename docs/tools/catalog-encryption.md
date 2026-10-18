# Catalog encryption

The catalog is encrypted at the column level by default, or whole-file with SQLCipher.

## Overview

DBConsole encrypts sensitive catalog columns with Laravel's encrypter by default. Where `pdo_sqlcipher` is available, the whole catalog file can be encrypted with a `PRAGMA key` instead. `SqlCipherManager` detects the driver and reports the active mode; `doctor` and `encryption:status` surface it. Whole-file encryption is capability-gated — absent SQLCipher, DBConsole degrades gracefully to column encryption rather than failing.

## Topology columns

A server's `host` and an account's `username` and `host` are encrypted with the app key while `catalog_encryption.encrypt_topology_columns` is `true`, the default. `false` writes them in plaintext. Either form reads back, so changing the setting needs no migration: each row keeps its form until it is saved again. A value that looks encrypted but does not decrypt still fails, as it always did.

---

[← Docs index](../../README.md#documentation)
