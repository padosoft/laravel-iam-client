---
title: Home
description: The drop-in Laravel client that delegates authorization to a Laravel IAM server — fail-closed.
---

# Laravel IAM — Client

`padosoft/laravel-iam-client` lets a Laravel app delegate **authorization** to a central
[Laravel IAM](https://github.com/padosoft) Policy Decision Point (PDP) — without changing how the app is
written. You keep using middleware, `Gate`, policies and `$user->can()`; the answers come from IAM.

::: callout tip "Two modes, one app code"
Run `mode=local` (the IAM server lives in the same app — the client calls the PDP in-process) or
`mode=http` (remote server via the Admin API). Your controllers and routes don't change between them — just
one env var.
:::

## What it gives you

- `iam.can:<permission>` middleware — a drop-in replacement for Spatie's `permission:`, decided centrally.
- `iam.auth` middleware — fail-closed 401 when there's no resolvable subject.
- A **Gate adapter** so `$user->can('app:perm')`, `@can` and `authorize()` consult IAM.
- Pluggable, fail-closed transports (`LocalDecider`, `HttpDecider`) + a short-TTL `CachingDecider`.
- Step-up awareness: a permit that needs a higher assurance level is treated as *not yet granted*.

## Install

```bash
composer require padosoft/laravel-iam-client
php artisan vendor:publish --tag=laravel-iam-client-config
```

## Next

- [Getting started](getting-started.md) — configure a transport and protect your first route.
- [Concepts](concepts.md) — the mental model: deciders, fail-closed, `granted()` vs `allowed`.
- [Middleware & Gate](middleware-and-gate.md) — `iam.can`, `iam.auth`, the Gate adapter.
- [Configuration](configuration.md) — every `iam-client.php` key and env var.
- [Reference](reference.md) — the public classes and their signatures.
