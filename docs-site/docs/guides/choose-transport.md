---
title: Choose a transport
description: local (in-process PDP) vs http (remote Admin API) — the trade-offs, how the service provider selects one, and how to switch with a single env var.
---

# Choose a transport

The client speaks to the PDP through a [`Decider`](/architecture/transports). Two concrete transports answer
the same `decide(DecisionRequest): IamDecision` contract, so your application code is identical either way.

## `local` vs `http` at a glance

| | `local` | `http` |
|---|---|---|
| Where the PDP runs | in the **same app** (in-process) | a **remote** IAM server |
| Mechanism | calls `AuthorizationEngine::check()` directly | `POST {base}/decisions/check` with a Bearer token |
| Network cost | none | one HTTP round-trip per uncached decision |
| Requires | the server package installed locally | a reachable server + service token |
| Typical use | modular monolith / same-repo deployment | distributed services |
| Failure mode | engine exception → **deny** | timeout / non-2xx / bad body → **deny** |

Both are **fail-closed**: see [Fail-closed authorization](/concepts/fail-closed).

## How the provider selects one

```php
// IamClientServiceProvider::makeDecider()
$base = mode === 'http'
    ? new HttpDecider(new GuzzleClient(['timeout' => http.timeout]), http.base_url, http.token)
    : new LocalDecider($app->make(AuthorizationEngine::class));

return cache.enabled ? new CachingDecider($base, $store, cache.ttl, true) : $base;
```

So `iam-client.mode` is the only switch between transports, and `iam-client.cache.enabled` decides whether
the result is wrapped in the cache decorator.

```mermaid
flowchart TD
    MODE{"iam-client.mode"}
    MODE -->|http| H["HttpDecider<br/>Guzzle · base_url · token · timeout"]
    MODE -->|local / anything else| L["LocalDecider<br/>AuthorizationEngine"]
    H --> CW{"cache.enabled?"}
    L --> CW
    CW -->|true| C["CachingDecider(inner, store, ttl)"]
    CW -->|false| RAW["raw decider"]
```

::: callout info "Local is the default"
`mode` defaults to `local`. Any value other than `http` selects the local transport — but set it explicitly
to `local` for clarity.
:::

## `local` setup

```dotenv
IAM_CLIENT_MODE=local
IAM_CLIENT_APP=billing
IAM_CLIENT_ORG=org_acme
```

Requires an `AuthorizationEngine` binding in the container — install the IAM server in the same app
([`padosoft/laravel-iam-server`](https://doc.laravel-iam-server.padosoft.com)). The client resolves it and
calls the PDP with no network hop, which is the fastest and most reliable path.

## `http` setup

```dotenv
IAM_CLIENT_MODE=http
IAM_CLIENT_BASE_URL=https://iam.example.com/api/iam/v1
IAM_CLIENT_TOKEN=your-service-bearer-token
IAM_CLIENT_APP=billing
IAM_CLIENT_ORG=org_acme
```

`HttpDecider` posts `DecisionRequest::toArray()` as JSON to `{base}/decisions/check`, sends
`Authorization: Bearer <token>` (omitted if the token is null), and disables Guzzle's HTTP exceptions
(`http_errors => false`) so it can map any non-2xx to a clean `deny("http {status}")` instead of throwing.

::: callout tip "The base URL already includes the API version" icon:link
Set `IAM_CLIENT_BASE_URL` to the versioned root, e.g. `https://iam.example.com/api/iam/v1`. The client
appends `/decisions/check` to it. The server wraps responses in `{ "data": {...} }`, which the client
unwraps transparently.
:::

### Authenticating to the server

Pick **one** way to authenticate the `http` transport (in order of precedence when several are set):

| Mode | Set | Notes |
|---|---|---|
| private_key_jwt | `http.client_id` + `http.private_key` (+ `http.private_key_kid`) | RFC 7523; asymmetric, no shared secret. Selected only when **both** `client_id` and `private_key` are set; wins over the others. |
| client_credentials | `http.client_id` + `http.client_secret` | The SDK obtains/renews the token itself and self-fetches an auto-rotated secret — zero-downtime rollover. |
| static token | `http.token` | A service bearer minted out of band. Used when neither of the above pairs is set. |

::: callout warning "Credentials are https-guarded, fail-closed (IAM-39 / IAM-39b)"
No credential travels over plain `http://`. A non-`https` URL — except loopback (`localhost` / `127.0.0.1` /
`::1`) — is **fail-closed**: nothing is sent and the PDP denies, rather than leaking a secret or Bearer in
clear. `http.allow_insecure=true` (`IAM_CLIENT_ALLOW_INSECURE`) lifts this for local development only.

The guard covers **every** credential-bearing path (`TransportGuard`):

- the **client_credentials** token endpoint (`oauth_url`) — no `client_secret` over http;
- the **private_key_jwt** token endpoint (`oauth_url`) — no signed assertion over http;
- the **decision call** to `base_url` — which carries the Bearer in *every* mode, including the static
  `token` — so `HttpDecider` returns `deny("insecure transport")` before sending;
- the **`iam:manifest:push`** command — which posts to `base_url` with the Bearer.

Redirects are disabled on all of these requests, so a `30x` `https→http` downgrade can't replay the body
(secret / assertion / Bearer) over cleartext. So both `http.oauth_url` **and** `http.base_url` must be `https`
in production (or loopback / `allow_insecure` in dev).
:::

::: callout info "Auto-rotated secret is encrypted at rest (IAM-25)"
When the server rotates the secret, the SDK self-fetches it and caches it **encrypted** (`Crypt` / `APP_KEY`)
with a bounded TTL — never in clear, never `forever` — so the cache can't become a recoverable copy of a live
credential. A secret cached in clear by an older release is transparently re-encrypted on first use.
:::

## Switching monolith → services

Because the application code never references a transport, extracting the IAM server into its own service is
a configuration change:

::: steps
1. **Stand up the remote server** and mint a service token for this app.
2. **Flip the env**
   ```diff
   - IAM_CLIENT_MODE=local
   + IAM_CLIENT_MODE=http
   + IAM_CLIENT_BASE_URL=https://iam.example.com/api/iam/v1
   + IAM_CLIENT_TOKEN=${IAM_SERVICE_TOKEN}
   ```
3. **Clear config cache** (`php artisan config:clear`) and deploy. Routes, controllers, the Gate adapter and
   the facade are unchanged.
:::

See [Deployment topologies](/operations/deployment-topologies) for the bigger picture.

## Gotchas

::: callout warning "local mode needs the engine binding"
In `local` mode without an `AuthorizationEngine` in the container, building the decider fails — install the
server package in the same app, or use `http`.
:::

::: callout warning "Tune the http timeout deliberately"
`http.timeout` (default 5s) caps how long a decision can block a request. A too-long timeout turns a slow PDP
into slow pages; a too-short one turns transient latency into spurious denies. Pair `http` with the
[decision cache](/guides/cache-decisions).
:::
