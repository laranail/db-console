# Contributing

Thanks for considering a contribution to `laranail/db-console`.

## Ground rules

- Discuss substantial changes in an issue before opening a pull request.
- Every pull request needs tests. Security-touching changes (identifier
  validation, quoting, SecretVault, RBAC scope resolution, audit chain) need
  tests that prove the invariant, not just coverage.
- The injection-corpus test and the secrets-never-logged architecture test must
  never be weakened. A pull request that loosens either will be declined.
- Only engine classes may build SQL or interpolate identifiers. Services,
  commands, controllers, and Livewire components never produce statement
  strings.

## Local development

```bash
composer install
composer test        # Pest (unit + feature + architecture)
composer lint        # pint --test, phpstan (level 8), rector --dry-run
```

The suite runs entirely on in-memory SQLite — no external servers or Docker.
Live multi-engine integration (MySQL/MariaDB/PostgreSQL) against a real engine
matrix lives in [`laranail/db-console-boilerplate`](https://github.com/laranail/db-console-boilerplate),
which owns the Docker stack and exercises the package end-to-end.

## Coding conventions

- PHP 8.4+, `declare(strict_types=1)` in every file.
- Laravel Pint (laravel preset) formats the code; PHPStan level 8 must pass.
- Closed value sets are `laranail/enumerator` enums; open sets are validated
  value objects or catalog rows.
- Artisan commands are namespaced `laranail::db-console.<command>` with the
  short `db-console:<command>` alias.
- User-facing strings live in `resources/lang/en/` under the `db-console::`
  namespace; do not hardcode message literals.

## Commit messages

- Subject in imperative mood, 72 characters or fewer.
- Body explains why, not what.
- No emoji, no AI attribution.
