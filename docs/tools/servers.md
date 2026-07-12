# Servers & connections

`ServerRegistry` and `AdminConnection` are the only components that touch a managed server.

## Overview

A **server** is a named target with an engine and a dedicated admin connection. `ServerRegistry` resolves servers from config (`servers.{name}`) and the catalog, caches them, and checks reachability. `AdminConnection` is the sole thing that runs a statement against a server; it translates every driver error into a DBConsole exception (never leaking SQL or credentials) and always runs over the dedicated admin connection — never your app's default.

## TLS

Each server carries a `tls` block (`enabled`, `verify`, `ca`, `cert`, `key`). `doctor` errors on a non-local server with TLS off and warns when TLS is on but unverified.

## Multi-server isolation

An operation names its server; the registry resolves that server's engine and connection. An operation on one server can never touch another.

---

[← Docs index](../../README.md#documentation)
