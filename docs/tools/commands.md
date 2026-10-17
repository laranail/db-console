# Commands

The full Artisan surface, each under the namespaced `laranail::db-console.<command>` name.

## Overview

Every command ships as `laranail::db-console.<command>`. Groups: `db:create|list|drop`, `user:create|list|password|drop|edit`, `grant|revoke|attach|detach`, `wizard`, `reconcile`, `server:add|list|use`, `audit:view|verify`, `secrets:rotate|driver`, `encryption:status`, `role:list|create|assign|revoke`, `access:show|check`, `token:issue`, `webhook:list|add|remove`, plus `security.recover`, `doctor` and `install`. Destructive commands require typed confirmation (or `--force` in CI); `--generate` prints a password once. All accept `--no-interaction` for scripting.

## Break-glass

`security.recover <user>` lifts db-console's assurance requirements for one operator for 15 minutes, raises a critical alert, and refuses to run from an HTTP request. A command that carries an authenticated operator is held to the same assurance as the API and the WebUI; a shell run with none is not. See [Operator assurance](operator-assurance.md).

## Deprecated alias

The install command was `db-console:install` until 0.1. That name is still registered, hidden, as a deprecated alias: it prints one line naming `laranail::db-console.install`, then runs it and returns its exit code. It is removed no earlier than the next minor after 0.1. No other command carries a bare alias.

---

[← Docs index](../../README.md#documentation)
