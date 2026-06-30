---
title: Concepts
description: The mental model behind the client — deciders, fail-closed transport, granted() vs allowed, caching.
---

# Concepts

## The problem

Centralizing authorization in one PDP is the right call — one place for RBAC + ABAC + ReBAC, step-up,
tenant isolation and audit. But if every consuming app has to learn a new SDK, speak a new vocabulary and
sprinkle bespoke calls everywhere, adoption dies. Apps already know Laravel authorization: middleware,
`Gate`, policies, `$user->can()`. The client's job is to make the central PDP answer *through those same
tools*.

## Mental model

```
$user->can('billing:invoices.update', $invoice)
        │
        ▼
   IamGateAdapter (Gate::before, intercepts "namespaced" abilities)
        │
        ▼
   IamClient::check()  ──►  DecisionRequest  ──►  Decider
                                                   ├─ LocalDecider  → AuthorizationEngine (in-process PDP)
                                                   ├─ HttpDecider   → POST /decisions/check (Admin API)
                                                   └─ CachingDecider (decorator, short TTL)
        │
        ▼
   IamDecision { allowed, requiresStepUp, ... } ──► granted()
```

## Core entities

- **`Decider`** — the transport seam: `decide(DecisionRequest): IamDecision`. The app never knows which
  concrete transport runs.
- **`DecisionRequest`** — the normalized query: subject, permission, organization/application, resource,
  ABAC `context`, current AAL, `explain`. Its `cacheKey()` hashes *all* of these.
- **`IamDecision`** — the normalized outcome. `allowed` is the raw PDP verdict; `requiresStepUp` flags a
  permit that needs a higher assurance level; `granted()` combines them.
- **`IamClient` / `Iam` facade** — the application-facing API (`can` / `denies` / `check`).
- **`IamGateAdapter`** — wires the client into Laravel's `Gate`.

## Example

```php
$decision = Iam::check($user, 'warehouse:stock.adjust', ['amount' => 300, 'resource' => 'wh_milan']);

$decision->allowed;        // PDP said permit
$decision->requiresStepUp; // ...but only with a higher AAL
$decision->granted();      // allowed && !requiresStepUp  ← use THIS to gate
```

## Anti-patterns

::: callout danger "Don't gate on `allowed`"
`allowed === true` can still mean "permitted only after step-up". Middleware and the Gate adapter use
`granted()` precisely so a low-AAL session can't slip through. Never branch on `allowed` alone to grant
access.
:::

::: callout danger "Don't add a fail-open switch"
A tempting "if the PDP is down, allow" flag turns an outage into a breach. The transports deny on every
error by design. Tolerating downtime is an *application* decision (e.g. degrade gracefully, queue, read a
cached permit) — never a transport default.
:::

::: callout warning "Don't cache `explain`"
Explanations must be fresh and aren't shareable across contexts. `CachingDecider` skips them.
:::

## Why it's built this way

The decider abstraction means the same controller works whether IAM is in-process or across the network —
so a modular monolith can become distributed services without an app rewrite. The fail-closed transport and
`granted()` semantics make the safe path the default path: you have to go out of your way to make it
insecure, not the other way around.
