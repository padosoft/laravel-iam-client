---
title: Architecture overview
description: The components of laravel-iam-client, how they're wired by the service provider, and the layering from app surface to transport.
---

# Architecture overview

## The shape of the package

`laravel-iam-client` is deliberately small and layered. Each layer has one job and depends only on the layer
below it.

```mermaid
flowchart TD
    subgraph surface["App surface"]
        MW["iam.can / iam.auth middleware"]
        GA["IamGateAdapter (Gate::before)"]
        FA["Iam facade"]
    end
    subgraph core["Application API"]
        IC["IamClient<br/>can / denies / check / request"]
    end
    subgraph dto["Contract DTOs"]
        DR["DecisionRequest"]
        ID["IamDecision"]
    end
    subgraph transport["Transport (Decider)"]
        CD["CachingDecider"]
        LD["LocalDecider"]
        HD["HttpDecider"]
    end
    MW --> IC
    GA --> IC
    FA --> IC
    IC --> DR
    IC --> CD
    CD --> LD
    CD --> HD
    LD --> ENG["AuthorizationEngine (in-process PDP)"]
    HD --> API["Admin API · POST /decisions/check"]
    LD --> ID
    HD --> ID
```

## Components

| Component | Namespace | Role |
|---|---|---|
| `IamClient` | `Padosoft\Iam\Client` | Application API; builds a `DecisionRequest`, returns an `IamDecision`. |
| `Iam` facade | `…\Client\Facades` | Static sugar over `IamClient`. |
| `DecisionRequest` | `…\Client` | Immutable query DTO; `toArray()` + `cacheKey()`. |
| `IamDecision` | `…\Client` | Immutable outcome DTO; `granted()`, `fromArray()`, `toArray()`. |
| `Decider` | `…\Client\Contracts` | Transport interface: `decide(DecisionRequest): IamDecision`. |
| `LocalDecider` / `HttpDecider` / `CachingDecider` | `…\Client\Deciders` | In-process / remote / caching transports. |
| `IamGateAdapter` | `…\Client\Gate` | Registers `Gate::before`. |
| `IamAuthenticate` / `IamCan` | `…\Client\Http\Middleware` | The `iam.auth` / `iam.can` aliases. |
| `IamClientServiceProvider` | `…\Client` | Wires it all together. |

## Wiring (the service provider)

`IamClientServiceProvider` extends `spatie/laravel-package-tools`' `PackageServiceProvider`.

- **`packageRegistered()`** binds three singletons: `Decider` (mode + cache), `IamClient` (config),
  `IamGateAdapter` (intercept).
- **`packageBooted()`** aliases the middleware *if free*, and registers the Gate adapter when
  `gate.enabled`.

```mermaid
flowchart LR
    CFG["config/iam-client.php"] --> SP["IamClientServiceProvider"]
    SP -->|mode + cache| DEC["Decider singleton"]
    SP -->|config| CL["IamClient singleton"]
    SP -->|intercept| AD["IamGateAdapter singleton"]
    SP -->|if free| ALIAS["iam.can / iam.auth aliases"]
    SP -->|if gate.enabled| GREG["Gate::before registered"]
```

## Design properties

- **Transport-agnostic application code.** The surface (middleware, Gate, facade) depends on `IamClient`,
  which depends on the `Decider` *interface* — never a concrete transport. Swapping `local` ↔ `http` is a
  config change. See [Transports](/architecture/transports).
- **Immutable DTOs.** `DecisionRequest` and `IamDecision` are `final readonly`, so a decision can't be
  mutated after the PDP returns it, and a request can't drift between building and sending.
- **Fail-closed at every layer.** Subject resolution, transport, and response parsing each default to deny.
  See [Fail-closed authorization](/concepts/fail-closed).
- **Non-invasive coexistence.** The Gate adapter intercepts only namespaced abilities by default, and the
  middleware aliases yield to any already-registered `iam.can`. The client slots into an existing app
  without displacing its authorization.

## Dependencies

The package depends on [`padosoft/laravel-iam-contracts`](https://doc.laravel-iam-contracts.padosoft.com)
for the `AuthorizationEngine` interface (the `local` seam) and shared DTO conventions, and on
`guzzlehttp/guzzle` for the `http` transport. It does **not** depend on the server package at runtime in
`http` mode — only the contract.

## See also

- [Decision pipeline](/architecture/decision-pipeline) — a single request, end to end.
- [Transports (the Decider seam)](/architecture/transports)
- [Architecture decisions (ADR)](/architecture/decisions)
