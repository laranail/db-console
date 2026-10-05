# Changelog

All notable changes to `laranail/db-console` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **`audit:view --limit=<non-numeric>` printed nothing.** `(int)` on a typo is `0`, and `limit(0)`
  returns no rows -- so a mistyped limit rendered an empty audit view, which reads exactly like
  "there is no audit history" rather than like a bad argument. Now `intOption('limit', 25)`, which
  falls back to the documented default.

- **A repeatable option containing an empty entry carried it through.** `--db=a --db=` produced
  `['a', '']`, and the empty string was passed on as a database, user, role, ability or event name.
  The six `(array)` casts are now `arrayOption()`, which trims and drops empties.

- **`docs/tools/commands.md` documented a `db-console:<command>` alias for every command, and
  `docs/tools/api.md` named the API middleware `db-console.api-guard`.** No command declares an
  alias, and the middleware is `laranail-db-console.api-guard`.

### Changed

- `laravel/framework ^13.0` is now declared in `require`. `src/` uses `Dispatchable`, `FormRequest` from `Illuminate\Foundation`, which no `illuminate/*` component ships, so the dependency only arrived through the host application.
- The base `DBConsoleCommand` applies `laranail/package-tools`' `Commands\Concerns\ReadsOptions`;
  its own `--server` resolution and `ServerAddCommand`'s `--host`/`--port` handling were the same
  normalisation written out by hand and now use the accessors.

- **`DbCreateCommand` no longer declares a private `stringOption()`.** It was the trait's method
  reimplemented, and once the base applied the trait PHP refused the class outright -- a private
  method cannot narrow a protected one. Removing it is behaviour-preserving: both return `''` for
  a non-string value.

- **The gate abilities and the install command are vendor-scoped.** The 21 abilities are
  `laranail-db-console.<permission>` (was `db-console.<permission>`), and the install command is
  `laranail::db-console.install` (was `db-console:install`). Both lived in flat, host-owned
  registries, where a sibling package or the application claiming the same key silently replaces
  it. `ConsolePermission::ability()` returns the scoped name, so the audit trail records it as the
  target of a denied action, and `install` seeds permission rows under it. Rows stored before the
  rename keep their names and still resolve. Requires `laranail/package-tools ^0.1.3`.

### Added

- **`ConsolePermission::fromAbility()`** reads a gate ability or stored permission name in either
  form, and **`deprecatedAbility()`** names the pre-0.1 ability. Both RBAC drivers read stored names
  through `fromAbility()`.
- **`tests/Feature/NamingConventionTest.php`** reads the live gate, Artisan and middleware
  registries through package-tools' `AssertsRegisteredNames`, and checks every deprecated alias
  answers as its replacement and announces itself once.

- **`assertNoNullOnlyOptionGuards()` is enforced over `src/`.**

### Deprecated

- **Gate abilities `db-console.<permission>` (21).** Each is still defined and asks the gate for its
  `laranail-db-console.*` ability, so a host's definition or before/after callback for the scoped
  name governs the old one too. The first check of each raises one `E_USER_DEPRECATED` notice.
- **Command `db-console:install`.** Still registered, hidden; it prints one line naming
  `laranail::db-console.install`, then runs it and returns its exit code.

Both are removed no earlier than the next minor after 0.1.

## [0.1.0] - 2026-07-11

Initial public release.

[Unreleased]: https://github.com/laranail/db-console/compare/v0.1.0...HEAD
