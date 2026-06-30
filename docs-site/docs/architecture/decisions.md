---
title: Architecture decisions (ADR)
description: The load-bearing design decisions of laravel-iam-client — fail-closed-only, granted() semantics, the Decider seam, reserved context keys, alias non-clobbering, and the {data} unwrap — each as Problem / Decision / Consequences.
---

# Architecture decisions (ADR)

A compact record of the decisions that shape this package. Each is *Problem → Decision → Consequences*, so
you can see not just *what* the code does but *why* — and what you'd be trading away to change it.

## ADR-1 — Fail-closed transports, no fail-open switch

::: collapsible "Problem → Decision → Consequences"
**Problem.** An authorization client on the request critical path must never turn an error into an allow. But
operators under outage pressure are tempted to add a "let it through" escape hatch.

**Decision.** Every transport denies on every failure (unreachable PDP, non-2xx, bad body, engine
exception), and there is deliberately **no** `fail_open` config key. Tolerating downtime is an
application-level choice, made consciously.

**Consequences.** A PDP outage denies until recovery — correct for a control plane, but it pushes
availability work to topology (HA, `local` mode, caching). There is no lever to "soften" the transport, by
design. See [Fail-closed authorization](/concepts/fail-closed).
:::

## ADR-2 — `granted()` folds in step-up

::: collapsible "Problem → Decision → Consequences"
**Problem.** A PDP can return *"permitted, but only at a higher AAL"*. A flat `allowed` boolean would let a
low-assurance session perform a sensitive action.

**Decision.** `IamDecision` carries `allowed` and `requiresStepUp` separately, and exposes
`granted() = allowed && !requiresStepUp`. Middleware, the Gate adapter, and `Iam::can()` all gate on
`granted()`.

**Consequences.** Declarative paths are step-up-safe automatically; a permit needing step-up is treated as
not-yet-granted. The cost is one extra concept (`granted()` vs `allowed`), which callers must respect — gating
on `allowed` reintroduces the hole. See [granted() vs allowed](/concepts/granted-vs-allowed).
:::

## ADR-3 — The `Decider` seam decouples app code from transport

::: collapsible "Problem → Decision → Consequences"
**Problem.** Apps shouldn't have to change when the IAM server moves from in-process to across the network.

**Decision.** A one-method `Decider` interface (`decide(DecisionRequest): IamDecision`) with three
implementations — `LocalDecider`, `HttpDecider`, `CachingDecider`. `IamClient` depends only on the interface.

**Consequences.** Switching `local` ↔ `http` is an env change; controllers, routes, the Gate adapter and the
facade are untouched. A modular monolith can become services without an app rewrite. The trade-off is a thin
extra indirection, which also makes a custom transport trivial. See [Transports](/architecture/transports).
:::

## ADR-4 — Reserved context keys vs ABAC facts

::: collapsible "Problem → Decision → Consequences"
**Problem.** Callers pass one flat `$context` array, but some entries are *query routing* (organization,
resource, AAL…) and the rest are *ABAC facts* the PDP evaluates. They must be separated.

**Decision.** A fixed reserved set — `organization`, `application`, `resource`, `aal`, `explain` — is pulled
out (and removed) by `IamClient::request()`; everything else is forwarded as ABAC `context`. Missing reserved
values fall back to config defaults.

**Consequences.** Call sites stay terse (defaults cover org/app), and the split is predictable. The catch:
those five names can't double as ABAC fact names — a fact literally named `resource` must be renamed. See
[ABAC context & ReBAC resources](/concepts/context-and-resources).
:::

## ADR-5 — Don't clobber an existing `iam.can` alias

::: collapsible "Problem → Decision → Consequences"
**Problem.** In a same-app deployment, the IAM server already registers an `iam.can` alias for its Admin API.
If the client overwrote it, the Admin API would break.

**Decision.** The provider registers the `iam.can` / `iam.auth` aliases **only if those names aren't already
taken**. App routes can always reference the client middleware class explicitly.

**Consequences.** The client coexists with the server in one app without collision. The subtlety: in such a
deployment, `iam.can:` on a route may resolve to the *server's* middleware, so use the explicit
`IamCan::class` form when you specifically want the client's. See
[Protect routes](/guides/protect-routes).
:::

## ADR-6 — Transparently unwrap the `{data}` envelope

::: collapsible "Problem → Decision → Consequences"
**Problem.** The server's Admin API wraps responses in `{ "data": {...} }`. Reading the wrong level would make
`fromArray()` see no `allowed` field and silently deny every request.

**Decision.** `HttpDecider` unwraps: if the decoded body has an array `data` key, parse that; otherwise parse
the body flat (so a non-enveloped body still works).

**Consequences.** The client is robust to both enveloped (Admin API) and flat (e.g. proxied local PDP)
responses. It also means the client tolerates exactly one level of `data` wrapping — a doubly-wrapped or
differently-keyed envelope would need a code change. See [The decision contract](/concepts/decision-contract).
:::

## ADR-7 — Short-TTL caching of deterministic decisions

::: collapsible "Problem → Decision → Consequences"
**Problem.** `http` decisions cost a round-trip; many checks repeat identical inputs within seconds.

**Decision.** A `CachingDecider` keys decisions by a SHA-256 of all inputs and reuses them for a short TTL
(default 30s). `explain` queries bypass the cache; only computed decisions are stored.

**Consequences.** Big drop in round-trips with a bounded staleness window — the TTL is effectively your
revocation latency. Caching never weakens fail-closed (an error caches at most a deny). See
[Cache decisions](/guides/cache-decisions).
:::

## See also

- [Architecture overview](/architecture/overview)
- [Fail-closed by design](/best-practices/fail-closed-design)
