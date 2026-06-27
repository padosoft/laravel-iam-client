<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client;

/**
 * Richiesta di decisione lato client (doc 09 §5). È il DTO che il client traduce verso il PDP:
 * `mode=local` → array per `AuthorizationEngine::check()`; `mode=http` → body JSON dell'Admin API
 * (`/decisions:check`). Disaccoppia l'app consumer dai value object interni del server.
 */
final readonly class DecisionRequest
{
    /** @param array<string, mixed> $context fatti ABAC (amount, time, …) */
    public function __construct(
        public string $permission,
        public string $subjectId,
        public string $subjectType = 'user',
        public ?string $organization = null,
        public ?string $application = null,
        public ?string $resource = null,
        public array $context = [],
        public string $currentAal = 'aal1',
        public bool $explain = false,
    ) {}

    /**
     * Chiave di cache stabile: la decisione dipende da TUTTI gli input (incl. context ABAC e AAL),
     * quindi vanno tutti nella chiave, altrimenti due query diverse condividerebbero un esito.
     */
    public function cacheKey(): string
    {
        return hash('sha256', (string) json_encode([
            $this->subjectType, $this->subjectId, $this->permission,
            $this->organization, $this->application, $this->resource,
            $this->context, $this->currentAal,
        ]));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'subject' => ['type' => $this->subjectType, 'id' => $this->subjectId],
            'permission' => $this->permission,
            'organization' => $this->organization,
            'application' => $this->application,
            'resource' => $this->resource,
            'context' => $this->context,
            'current_aal' => $this->currentAal,
            'explain' => $this->explain,
        ];
    }
}
