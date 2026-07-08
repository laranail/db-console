# Catalog encryption

The catalog is encrypted at the column level by default, or whole-file with SQLCipher.

## Overview

DBConsole encrypts sensitive catalog columns with Laravel's encrypter by default. Where `pdo_sqlcipher` is available, the whole catalog file can be encrypted with a `PRAGMA key` instead. `SqlCipherManager` detects the driver and reports the active mode; `doctor` and `encryption:status` surface it. Whole-file encryption is capability-gated — absent SQLCipher, DBConsole degrades gracefully to column encryption rather than failing.

---

[← Docs index](../../README.md#documentation)
