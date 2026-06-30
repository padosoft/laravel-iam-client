---
title: Reference
description: The public classes of laravel-iam-client and their signatures, grouped by namespace.
---

# Reference

All classes live under `Padosoft\Iam\Client\`.

## Facade — `Facades\Iam`

```php
Iam::can(Authenticatable|string|null $user, string $ability, array $context = []): bool
Iam::denies(Authenticatable|string|null $user, string $ability, array $context = []): bool
Iam::check(Authenticatable|string|null $user, string $ability, array $context = []): IamDecision
```

Reserved `$context` keys are pulled out and mapped to the decision query: `organization`, `application`,
`resource`, `aal`, `explain`. Everything else is sent as ABAC facts.

## `IamClient`

The class behind the facade.

```php
public function can(Authenticatable|string|null $user, string $ability, array $context = []): bool
public function denies(Authenticatable|string|null $user, string $ability, array $context = []): bool
public function check(Authenticatable|string|null $user, string $ability, array $context = []): IamDecision
public function request(string $subjectId, string $ability, array $context = []): DecisionRequest
public function resolveSubjectId(Authenticatable|string|null $user): string
```

`check()` returns `IamDecision::deny('no-subject')` when no subject can be resolved (fail-closed).

## `Contracts\Decider` and the transports

```php
interface Decider {
    public function decide(DecisionRequest $request): IamDecision;
}
```

- **`Deciders\LocalDecider`** — `__construct(AuthorizationEngine $engine)`. Calls the in-process PDP; any
  exception → `deny('engine: …')`.
- **`Deciders\HttpDecider`** — `__construct(ClientInterface $http, string $baseUrl, ?string $token)`. POSTs
  to `{baseUrl}/decisions/check` (unwrapping the `{data}` envelope); non-2xx, invalid body or transport
  error → `deny(...)`.
- **`Deciders\CachingDecider`** — `__construct(Decider $inner, CacheRepository $cache, int $ttl, bool $enabled = true)`.
  Caches by `DecisionRequest::cacheKey()`; bypasses cache when disabled, `ttl <= 0`, or `explain`.

## `DecisionRequest` (`final readonly`)

```php
new DecisionRequest(
    string  $permission,
    string  $subjectId,
    string  $subjectType = 'user',
    ?string $organization = null,
    ?string $application = null,
    ?string $resource = null,
    array   $context = [],          // ABAC facts
    string  $currentAal = 'aal1',
    bool    $explain = false,
);

public function cacheKey(): string   // sha256 over ALL inputs
public function toArray(): array     // engine array / Admin API body
```

## `IamDecision` (`final readonly`)

```php
new IamDecision(
    bool    $allowed,
    string  $decisionId = '',
    int     $policyVersion = 0,
    bool    $requiresStepUp = false,
    ?string $requiredAal = null,
    array   $explanation = [],
);

public static function deny(string $reason): self
public static function fromArray(array $data): self   // normalizes the PDP response
public function granted(): bool                        // allowed && !requiresStepUp  ← gate on this
public function toArray(): array
```

## `Gate\IamGateAdapter`

```php
public function __construct(IamClient $client, string $intercept = 'namespaced')
public function register(Gate $gate): void
public function decide(Authenticatable $user, string $ability, array $arguments = []): ?bool
```

`decide()` returns `null` for abilities it doesn't own (so local Gates/policies decide), otherwise the
binding verdict from `IamClient::check()->granted()`.

## Middleware

- **`Http\Middleware\IamAuthenticate`** — alias `iam.auth`. 401 when `$request->user()` is null.
- **`Http\Middleware\IamCan`** — alias `iam.can`. `handle($request, $next, string $permission, ?string $resourceParam = null)`.
  401 without a user, 403 when denied or step-up-required-but-unsatisfied. Binds `$resourceParam` (route
  value or model key) as the decision `resource`.

## Service provider

`IamClientServiceProvider` (spatie/laravel-package-tools). Binds `Decider`, `IamClient`, `IamGateAdapter`
as singletons, registers the `iam.can` / `iam.auth` aliases **only if not already registered**, and
registers the Gate adapter when `gate.enabled` is true.
