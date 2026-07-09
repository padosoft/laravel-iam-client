---
title: Configuration
description: Every iam-client.php key and environment variable, with defaults and meaning — mode, http, subject/defaults, cache, and gate.
---

# Configuration

Publish the config, then drive it with environment variables:

```bash
php artisan vendor:publish --tag=laravel-iam-client-config
```

## All keys

| Key | Env | Default | Meaning |
|---|---|---|---|
| `mode` | `IAM_CLIENT_MODE` | `local` | Transport: `local` = in-process PDP; `http` = remote Admin API. Any value ≠ `http` selects `local`. |
| `http.base_url` | `IAM_CLIENT_BASE_URL` | — | Versioned API root, e.g. `https://iam.example.com/api/iam/v1`. The client appends `/decisions/check`. |
| `http.token` | `IAM_CLIENT_TOKEN` | — | Bearer token for the Admin API. Omitted from the request when null. |
| `http.client_id` | `IAM_CLIENT_ID` | — | client_credentials auth; the SDK obtains/renews the token itself. Precedence over a static `token`. |
| `http.client_secret` | `IAM_CLIENT_SECRET` | — | The rotatable secret. An auto-rotated secret is cached **encrypted** with a bounded TTL (IAM-25). |
| `http.private_key` | `IAM_CLIENT_PRIVATE_KEY` | — | private_key_jwt (RFC 7523): ES256 PEM (inline or path). Precedence over `client_secret`. |
| `http.private_key_kid` | `IAM_CLIENT_PRIVATE_KEY_KID` | — | `kid` of the public key registered in IAM's JWKS. |
| `http.oauth_url` | `IAM_CLIENT_OAUTH_URL` | — | Token endpoint; derived from `base_url` when unset. |
| `http.allow_insecure` | `IAM_CLIENT_ALLOW_INSECURE` | `false` | IAM-39: allow credentials over `http://`. Fail-closed by default (non-`https`, except `localhost`, gets no secret). Dev only. |
| `http.timeout` | — | `5` | Guzzle request timeout (seconds). |
| `subject_type` | — | `user` | Subject `type` sent in every decision query. |
| `default_application` | `IAM_CLIENT_APP` | — | Default `application` when a call doesn't pass one. |
| `default_organization` | `IAM_CLIENT_ORG` | — | Default `organization` (tenant) when a call doesn't pass one. |
| `cache.enabled` | — | `true` | Wrap the transport in `CachingDecider`. |
| `cache.ttl` | — | `30` | Decision cache TTL in seconds. `<= 0` disables caching even when enabled. |
| `cache.store` | — | `null` | Laravel cache store name. `null` = default store. |
| `gate.enabled` | — | `true` | Register the `Gate::before` adapter. |
| `gate.intercept` | — | `namespaced` | `namespaced` = only abilities with `:`; `all` = every ability. |
| `gate.app_keys` | `IAM_CLIENT_APP_KEYS` | `[]` (all namespaced) | IAM-40: comma-separated app prefixes; intercept **only** these `app:` namespaces so third-party abilities aren't claimed. Each entry trimmed. |

## The published file

```php
return [
    'mode' => env('IAM_CLIENT_MODE', 'local'),

    'http' => [
        'base_url'        => env('IAM_CLIENT_BASE_URL'),   // e.g. https://iam.example.com/api/iam/v1
        'token'           => env('IAM_CLIENT_TOKEN'),        // static Bearer for the Admin API
        'client_id'       => env('IAM_CLIENT_ID'),           // client_credentials (self-renewing token)
        'client_secret'   => env('IAM_CLIENT_SECRET'),       // rotatable; auto-rotated copy cached encrypted (IAM-25)
        'private_key'     => env('IAM_CLIENT_PRIVATE_KEY'),  // private_key_jwt: ES256 PEM or path
        'private_key_kid' => env('IAM_CLIENT_PRIVATE_KEY_KID'),
        'oauth_url'       => env('IAM_CLIENT_OAUTH_URL'),     // else derived from base_url
        'timeout'         => 5,
        'allow_insecure'  => (bool) env('IAM_CLIENT_ALLOW_INSECURE', false),  // IAM-39: http:// only in dev
    ],

    'subject_type'         => 'user',
    'default_application'  => env('IAM_CLIENT_APP'),
    'default_organization' => env('IAM_CLIENT_ORG'),

    'cache' => [
        'enabled' => true,
        'ttl'     => 30,     // seconds
        'store'   => null,   // null = default store
    ],

    'gate' => [
        'enabled'   => true,
        'intercept' => 'namespaced',  // or 'all'
        'app_keys'  => [],            // IAM-40: e.g. ['warehouse','billing']; empty = all namespaced
    ],
];
```

## Example `.env` blocks

::: tabs
== tab "Remote (http)" icon:cloud
```dotenv
IAM_CLIENT_MODE=http
IAM_CLIENT_BASE_URL=https://iam.example.com/api/iam/v1
IAM_CLIENT_TOKEN=${IAM_SERVICE_TOKEN}
IAM_CLIENT_APP=billing
IAM_CLIENT_ORG=org_acme
```
== tab "Same app (local)" icon:server
```dotenv
IAM_CLIENT_MODE=local
IAM_CLIENT_APP=billing
IAM_CLIENT_ORG=org_acme
```
:::

## Notes

::: callout danger "There is no fail_open key"
The transport is always fail-closed: an unreachable PDP denies. Tolerating an outage is a conscious
[application choice](/best-practices/fail-closed-design), not a config setting.
:::

::: callout danger "Credentials require https (IAM-39)"
When authenticating with `client_id`/`client_secret` (or a static bearer to the token endpoint), the URL must
be `https`. A non-`https` endpoint (except `localhost`) receives **no** credentials and the PDP denies —
rather than leaking a secret in clear. `allow_insecure=true` lifts this for local dev only. An auto-rotated
secret is additionally cached **encrypted** at rest (IAM-25).
:::

::: callout warning "Cache TTL is your revocation latency"
A short TTL (default 30s) bounds how long a revoked grant keeps being honored on each node. `explain` queries
are never cached regardless. See [Cache decisions](/guides/cache-decisions).
:::

::: callout tip "Defaults keep call sites terse" icon:wand
Set `default_application` / `default_organization` once (via `IAM_CLIENT_APP` / `IAM_CLIENT_ORG`) and most
calls won't need to pass them — they're inherited unless overridden per call. See
[ABAC context & ReBAC resources](/concepts/context-and-resources).
:::

::: callout warning "Turn the Gate off for shadow mode"
Set `gate.enabled = false` while the [spatie bridge](/best-practices/migrating-from-spatie) runs in shadow
mode, so the adapter's enforcement doesn't pollute decision diffing.
:::

## Cache after changing config

In production with `config:cache`, run `php artisan config:clear` (or re-cache) after editing `.env` so the
new transport/cache/gate settings take effect.

## See also

- [Config & env reference](/reference/config-and-env)
- [Choose a transport](/guides/choose-transport)
- [Deployment topologies](/operations/deployment-topologies)
