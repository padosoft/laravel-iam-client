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
| `http.client_id` | `IAM_CLIENT_ID` | string\|null | `null` | client_credentials: SDK obtains/renews the token itself; takes precedence over a static `token` |
| `http.client_secret` | `IAM_CLIENT_SECRET` | string\|null | `null` | the rotatable secret; an auto-rotated secret is cached **encrypted** (`Crypt`/`APP_KEY`) with a bounded TTL, never in clear |
| `http.private_key` | `IAM_CLIENT_PRIVATE_KEY` | string\|null | `null` | private_key_jwt (RFC 7523): ES256 PEM (inline or path); takes precedence over `client_secret` |
| `http.private_key_kid` | `IAM_CLIENT_PRIVATE_KEY_KID` | string\|null | `null` | `kid` of the public key registered in IAM's JWKS |
| `http.oauth_url` | `IAM_CLIENT_OAUTH_URL` | string\|null | `null` | token endpoint; derived from `base_url` when unset |
| `http.allow_insecure` | `IAM_CLIENT_ALLOW_INSECURE` | bool | `false` | **IAM-39**: allow credentials over `http://`. Default `false` = fail-closed: a non-`https` token endpoint (except `localhost`) never receives a secret/bearer. Dev only |
| `http.timeout` | — | int | `5` | Guzzle timeout, seconds |
| `subject_type` | — | string | `user` | sent as `subject.type` |
| `default_application` | `IAM_CLIENT_APP` | string\|null | `null` | default `application` |
| `default_organization` | `IAM_CLIENT_ORG` | string\|null | `null` | default `organization` |
| `cache.enabled` | — | bool | `true` | wrap transport in `CachingDecider` |
| `cache.ttl` | — | int | `30` | seconds; `<= 0` disables caching |
| `cache.store` | — | string\|null | `null` | Laravel cache store; `null` = default |
| `gate.enabled` | — | bool | `true` | register `Gate::before` adapter |
| `gate.intercept` | — | string | `namespaced` | `namespaced` (only `:` abilities) or `all` |
| `gate.app_keys` | `IAM_CLIENT_APP_KEYS` | list | `[]` | **IAM-40**: comma-separated app prefixes; when set, intercept **only** abilities whose `app:` prefix is listed, so a third-party namespaced ability (e.g. `log:viewer`) isn't claimed/denied by IAM. Empty = all namespaced (historic behavior). Each entry trimmed |

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
| Scope the Gate to your apps only | `gate.app_keys=warehouse,billing` (leaves other namespaced abilities to local Gates) |
| Allow credentials over http:// (dev) | `http.allow_insecure=true` (never in production) |

## See also

- [Configuration](/operations/configuration)
- [PHP API reference](/reference/php-api)
- [Cache decisions](/guides/cache-decisions)
