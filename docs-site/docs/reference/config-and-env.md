---
title: Config & env
description: Quick-reference table of every iam-client.php key and its environment variable, with defaults and constraints.
---

# Config & env

A condensed reference. For prose and examples see [Configuration](/operations/configuration).

## Keys

| Key | Env | Type | Default | Constraint / note |
|---|---|---|---|---|
| `mode` | `IAM_CLIENT_MODE` | string | `local` | `http` selects the remote transport; any other value → `local` |
| `http.base_url` | `IAM_CLIENT_BASE_URL` | string | `null` | versioned API root; client appends `/decisions/check` |
| `http.token` | `IAM_CLIENT_TOKEN` | string\|null | `null` | Bearer; omitted from request when null |
| `http.timeout` | — | int | `5` | Guzzle timeout, seconds |
| `subject_type` | — | string | `user` | sent as `subject.type` |
| `default_application` | `IAM_CLIENT_APP` | string\|null | `null` | default `application` |
| `default_organization` | `IAM_CLIENT_ORG` | string\|null | `null` | default `organization` |
| `cache.enabled` | — | bool | `true` | wrap transport in `CachingDecider` |
| `cache.ttl` | — | int | `30` | seconds; `<= 0` disables caching |
| `cache.store` | — | string\|null | `null` | Laravel cache store; `null` = default |
| `gate.enabled` | — | bool | `true` | register `Gate::before` adapter |
| `gate.intercept` | — | string | `namespaced` | `namespaced` (only `:` abilities) or `all` |

## Env-only quick start

::: tabs
== tab "http"
```dotenv
IAM_CLIENT_MODE=http
IAM_CLIENT_BASE_URL=https://iam.example.com/api/iam/v1
IAM_CLIENT_TOKEN=${IAM_SERVICE_TOKEN}
IAM_CLIENT_APP=billing
IAM_CLIENT_ORG=org_acme
```
== tab "local"
```dotenv
IAM_CLIENT_MODE=local
IAM_CLIENT_APP=billing
IAM_CLIENT_ORG=org_acme
```
:::

## Defaulting rules (how config is read)

The service provider reads config defensively — a value of the wrong type falls back to the default:

| Reader | Falls back when |
|---|---|
| string keys (`mode`, `http.*`, `gate.intercept`, …) | value is not a non-empty string |
| `cache.enabled`, `gate.enabled` | value is not a bool → the documented default |
| `http.timeout`, `cache.ttl` | value is not an int → the documented default |

So a malformed env value can't silently produce undefined behavior — it produces the safe default.

## Behavior toggles at a glance

| Want to… | Set |
|---|---|
| Use the remote server | `mode=http` + `http.base_url` (+ `http.token`) |
| Use the in-process PDP | `mode=local` (needs an `AuthorizationEngine` binding) |
| Disable decision caching | `cache.enabled=false` *or* `cache.ttl=0` |
| Share cache across nodes | `cache.store=redis` (or another shared store) |
| Stop the Gate adapter (e.g. shadow mode) | `gate.enabled=false` |
| Centralize every ability | `gate.intercept=all` (only after all are declared) |

## See also

- [Configuration](/operations/configuration)
- [PHP API reference](/reference/php-api)
- [Cache decisions](/guides/cache-decisions)
