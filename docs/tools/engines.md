# Engines

The engine layer is the only place SQL is built.

## Overview

Five engines implement one interface: `MySqlEngine`, `MariaDbEngine`, `PostgresEngine`, `SqlServerEngine`, `SqliteEngine`, resolved by `EngineFactory` from a server's declared engine type. Each turns validated value objects into exact statements through a `Quoter` (backtick, double-quote, or bracket) and declares its honest `Capabilities` — what it can and cannot do.

## The contract

An engine builds statements for creating and dropping databases and accounts, granting and revoking privileges, and reading grants. It never runs them — the `AdminConnection` does that. Because engines are the sole SQL producers, an architecture test forbids any other file from minting a `Statement`, and it must never be weakened.

## Honest capability degradation

SQLite has no account or privilege concept, so its capabilities report `false` and every account/grant call throws `UnsupportedOperation` rather than pretending. PostgreSQL scopes accounts through `pg_hba`, not DBConsole, so it reports that in its capability note. The UI and CLI only offer what the target actually supports.

---

[← Docs index](../../README.md#documentation)
