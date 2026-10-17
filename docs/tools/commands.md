# Commands

The full Artisan surface, each under the namespaced `laranail::db-console.<command>` name.

## Overview

Every command ships as `laranail::db-console.<command>`. Groups: `db:create|list|drop`, `user:create|list|password|drop|edit|grants`, `grant|revoke|attach|detach`, `wizard`, `reconcile`, `server:add|list|use`, `audit:view|verify`, `secrets:rotate|driver`, `encryption:status`, `role:list|create|assign|revoke`, `access:show|check`, `token:issue`, `webhook:list|add|remove`, plus `security.recover`, `doctor` and `install`. Destructive commands require typed confirmation (or `--force` in CI); `--generate` prints a password once. All accept `--no-interaction` for scripting.

## Reading an account's grants

`user:grants --user=<name> [--host=%] [--direct] [--json]` prints what an account may do, read live from the server and gated by `account.view`. On PostgreSQL each line says where a privilege comes from when it is not granted to the account itself (`via owner`, `via PUBLIC`, `via <role>`); `--direct` keeps the account's own and what it owns, and `--json` prints the structured entries the API returns. See [Engines](engines.md#postgresql).

## Break-glass

`security.recover <user>` lifts db-console's assurance requirements for one operator for 15 minutes, raises a critical alert, and refuses to run from an HTTP request. A command that carries an authenticated operator is held to the same assurance as the API and the WebUI; a shell run with none is not. See [Operator assurance](operator-assurance.md).

## Existing accounts and databases

`user:create`, `db:create` and `wizard` take `--on-existing=refuse|adopt|adopt_and_apply` (a database takes `refuse` or `adopt`). Without it, an interactive run that finds the resource already there asks what to do, naming each option's risk; `--no-interaction` applies the configured policy, `refuse` by default. A refusal names the `--on-existing` values the operator may use. See [Adopt an existing account or database](../recipes/adopt-existing-resources.md).

## Removed alias

The install command was `db-console:install` until 0.1, and that name was kept as a hidden alias through 0.1.1. It is gone: use `laranail::db-console.install`. No command carries a bare name.

---

[← Docs index](../../README.md#documentation)
