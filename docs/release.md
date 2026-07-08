# Release

Versioning and the release process for `laranail/db-console`.

## Versioning

Semantic versioning. Pre-1.0 the public surface is settling; breaking changes are noted in [UPGRADING.md](../UPGRADING.md) and the [CHANGELOG](../CHANGELOG.md).

## Cutting a release

Releases are tag-driven (`vX.Y.Z`). The release workflow extracts the tagged version's CHANGELOG section as the GitHub release body. See the org-wide shipping checklist before every release.

## The public surface

Stable across a minor series: the service classes and their signatures, the domain value objects, the shared validation Rules and `RuleProvider`, the domain events, and the CLI command names.

---

[← Docs index](../README.md#documentation)
