# Security policy

## Supported versions

Only the latest minor release of `laranail/db-console` receives security fixes.

## Reporting a vulnerability

Please report suspected vulnerabilities privately to **opensource@simtabi.com**.
Do not open a public issue for security reports.

You should receive an acknowledgement within 72 hours. Please include a
description of the issue, steps to reproduce, and any relevant environment
details (PHP version, Laravel version, database engine).

Because this package brokers privileged database credentials, reports touching
the `SecretVault` drivers, the identifier validation layer, the RBAC scope
resolver, or the audit chain are treated as highest priority.
