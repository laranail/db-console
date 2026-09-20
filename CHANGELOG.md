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

### Changed

- The base `DBConsoleCommand` applies `laranail/package-tools`' `Commands\Concerns\ReadsOptions`;
  its own `--server` resolution and `ServerAddCommand`'s `--host`/`--port` handling were the same
  normalisation written out by hand and now use the accessors.

- **`DbCreateCommand` no longer declares a private `stringOption()`.** It was the trait's method
  reimplemented, and once the base applied the trait PHP refused the class outright -- a private
  method cannot narrow a protected one. Removing it is behaviour-preserving: both return `''` for
  a non-string value.

### Added

- **`assertNoNullOnlyOptionGuards()` is enforced over `src/`.**

## [0.1.0] - 2026-07-11

Initial public release.
