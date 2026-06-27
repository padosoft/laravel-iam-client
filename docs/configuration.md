---
title: Configuration
description: Every iam-client.php key and environment variable, with safe defaults.
---

# Configuration

Publish the config, then drive it with env vars:

```bash
php artisan vendor:publish --tag=laravel-iam-client-config
```

## Keys

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `mode` | `IAM_CLIENT_MODE` | `local` | `local` = in-process PDP; `http` = remote Admin API |
| `http.base_url` | `IAM_CLIENT_BASE_URL` | — | Admin API base, e.g. `https://iam.example.com/api/iam/v1` |
| `http.token` | `IAM_CLIENT_TOKEN` | — | Bearer for the Admin API |
| `http.timeout` | — | `5` | HTTP timeout (seconds) |
| `subject_type` | — | `user` | Subject type sent in decision queries |
| `default_application` | `IAM_CLIENT_APP` | — | Default `application` for queries |
| `default_organization` | `IAM_CLIENT_ORG` | — | Default `organization` (tenant) for queries |
| `cache.enabled` | — | `true` | Cache decisions for a short TTL |
| `cache.ttl` | — | `30` | Decision cache TTL (seconds) |
| `cache.store` | — | `null` | Cache store (`null` = default store) |
| `gate.enabled` | — | `true` | Register the `Gate::before` adapter |
| `gate.intercept` | — | `namespaced` | `namespaced` (only `:` abilities) or `all` |

## Notes

::: callout danger "The transport is always fail-closed"
There is **no** `fail_open` key. An unreachable PDP denies. Tolerating an outage is a conscious application
choice, not a transport setting.
:::

::: callout warning "Cache TTL is a trade-off"
Decisions change when grants change. A short TTL (default 30s) bounds staleness. `explain` queries are
never cached.
:::

::: callout tip "Turn the Gate off for shadow mode"
Set `gate.enabled = false` when the migration bridge runs in shadow mode, so the adapter's enforcement
doesn't pollute the decision diffing.
:::

## Example `.env`

```dotenv
IAM_CLIENT_MODE=http
IAM_CLIENT_BASE_URL=https://iam.example.com/api/iam/v1
IAM_CLIENT_TOKEN=${IAM_SERVICE_TOKEN}
IAM_CLIENT_APP=billing
IAM_CLIENT_ORG=org_acme
```
