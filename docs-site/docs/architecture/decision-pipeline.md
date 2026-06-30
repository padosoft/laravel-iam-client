---
title: Decision pipeline
description: A single authorization decision traced end to end — from a route or call site through subject resolution, request building, caching, transport, and parsing.
---

# Decision pipeline

This page traces *one* decision from the moment your app asks to the moment it acts, naming every step in
order. It's the runtime companion to the [architecture overview](/architecture/overview).

## End-to-end sequence

```mermaid
sequenceDiagram
    autonumber
    participant Route as Route / call site
    participant MW as iam.can (or Gate / facade)
    participant Client as IamClient
    participant Cache as CachingDecider
    participant T as LocalDecider / HttpDecider
    participant PDP as PDP

    Route->>MW: request hits protected route
    MW->>MW: $request->user() — null? → 401
    MW->>MW: resolve resource ref (route param / model key)
    MW->>Client: can(user, permission, context)
    Client->>Client: resolveSubjectId(user) — '' ? → deny('no-subject')
    Client->>Client: build DecisionRequest (pull reserved keys, apply defaults)
    Client->>Cache: decide(request)
    alt explain OR cache disabled
        Cache->>T: decide(request)
    else cache lookup
        Cache->>Cache: get('iam:dec:'+cacheKey())
        alt hit
            Cache-->>Client: IamDecision::fromArray(cached)
        else miss
            Cache->>T: decide(request)
            T-->>Cache: IamDecision
            Cache->>Cache: put(key, decision.toArray(), ttl)
        end
    end
    T->>PDP: check() in-process / POST /decisions/check
    PDP-->>T: { allowed, requires_step_up, ... } (or error)
    T-->>Client: IamDecision (or deny on any error)
    Client-->>MW: decision.granted()
    MW->>Route: pass (true) or 403 (false)
```

## Step by step

::: steps
1. **Entry & authentication gate**
   For a route, `iam.can` first checks `$request->user()`; a null user aborts **401** before any PDP work.
   The Gate adapter and facade are entered with a user/subject already in hand.

2. **Resource resolution (middleware only)**
   With `iam.can:perm,routeParam`, the middleware reads `$request->route(routeParam)` and reduces an Eloquent
   model to `(string) getKey()`, or uses a scalar route value directly. The reference goes into
   `context['resource']`.

3. **Subject resolution**
   `IamClient::resolveSubjectId()` maps the user to a string id (`getAuthIdentifier()` for an
   `Authenticatable`, the string itself for a string, `''` for null). An empty id short-circuits to
   `deny('no-subject')`.

4. **Request building**
   `IamClient::request()` pulls the [reserved keys](/concepts/context-and-resources)
   (`organization`, `application`, `resource`, `aal`, `explain`) out of the context, applies config defaults,
   and constructs the immutable `DecisionRequest`. The remaining context is ABAC facts.

5. **Cache decorator**
   `CachingDecider` bypasses entirely when `explain` is set, caching is disabled, or `ttl <= 0`. Otherwise it
   looks up `'iam:dec:' + cacheKey()`; a hit rehydrates via `fromArray()`, a miss delegates and stores
   `toArray()` for `ttl` seconds.

6. **Transport**
   `LocalDecider` calls `AuthorizationEngine::check($request->toArray())` in-process; `HttpDecider` POSTs the
   same array to `{base}/decisions/check` and unwraps the `{data}` envelope. Either way, any failure becomes a
   `deny(...)`.

7. **Outcome**
   The caller evaluates `granted()` (= `allowed && !requiresStepUp`). Middleware turns `false` into **403**;
   the Gate adapter returns `false` (short-circuiting the gate); the facade returns the boolean or the full
   decision.
:::

## Where each failure exits

| Step | Failure | Result |
|---|---|---|
| 1 | no authenticated user (middleware) | 401 |
| 3 | unresolvable subject | `deny('no-subject')` |
| 6 (local) | engine throws | `deny('engine: …')` |
| 6 (http) | non-2xx / bad body / transport throw | `deny('http …' / 'invalid body' / 'transport: …')` |
| 7 | `granted()` is false | 403 / `false` |

Every non-happy exit is a denial — the [fail-closed](/concepts/fail-closed) invariant, visible end to end.

## Determinism & idempotency

Steps 3–6 are pure functions of the inputs: same inputs → same `cacheKey()` → same decision (until grants
change). That's what makes step 5's caching safe and what lets a retried request reuse a cached answer. See
[Cache decisions](/guides/cache-decisions).

## See also

- [Architecture overview](/architecture/overview)
- [The decision contract](/concepts/decision-contract)
- [Transports](/architecture/transports)
