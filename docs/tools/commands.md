# Commands

The full Artisan surface, each under a namespaced name and a short alias.

## Overview

Every command ships as `laranail::db-console.<command>` with a `db-console:<command>` alias. Groups: `db:create|list|drop`, `user:create|list|password|drop|edit`, `grant|revoke|attach|detach`, `wizard`, `reconcile`, `server:add|list|use`, `audit:view|verify`, `secrets:rotate|driver`, `encryption:status`, `role:list|create|assign|revoke`, `access:show|check`, `token:issue`, `webhook:list|add|remove`, plus `doctor` and `db-console:install`. Destructive commands require typed confirmation (or `--force` in CI); `--generate` prints a password once. All accept `--no-interaction` for scripting.

---

[← Docs index](../../README.md#documentation)
