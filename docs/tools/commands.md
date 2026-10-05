# Commands

The full Artisan surface, each under the namespaced `laranail::db-console.<command>` name.

## Overview

Every command ships as `laranail::db-console.<command>`. Groups: `db:create|list|drop`, `user:create|list|password|drop|edit`, `grant|revoke|attach|detach`, `wizard`, `reconcile`, `server:add|list|use`, `audit:view|verify`, `secrets:rotate|driver`, `encryption:status`, `role:list|create|assign|revoke`, `access:show|check`, `token:issue`, `webhook:list|add|remove`, plus `doctor` and `install`. Destructive commands require typed confirmation (or `--force` in CI); `--generate` prints a password once. All accept `--no-interaction` for scripting.

## Deprecated alias

The install command was `db-console:install` until 0.1. That name is still registered, hidden, as a deprecated alias: it prints one line naming `laranail::db-console.install`, then runs it and returns its exit code. It is removed no earlier than the next minor after 0.1. No other command carries a bare alias.

---

[← Docs index](../../README.md#documentation)
