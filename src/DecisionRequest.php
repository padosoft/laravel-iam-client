<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client;

/**
 * Richiesta di decisione lato client (doc 09 §5). È il DTO che il client traduce verso il PDP:
 * `mode=local` → array per `AuthorizationEngine::check()`; `mode=http` → body JSON dell'Admin API
 * (`/decisions/check`). Disaccoppia l'app consumer dai value object interni del server.
 */
final readonly class DecisionRequest
{
    /**
     * @param  array<string, mixed>  $context  fatti ABAC (amount, time, …)
     * @param  list<string>|null  $actors  catena di delega (`agent:<id>`, attore corrente per primo).
     *                                     Presente ⇒ decisione DELEGATA: intersezione utente ∩ agente
     *                                     via `/decisions/check-delegated`, MAI il check single-subject.
     * @param  string|null  $delegationGrantId  claim `pds_dgr` del token delegato (revoca mirata)
     */
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
        public ?array $actors = null,
        public ?string $delegationGrantId = null,
    ) {}

    /** La richiesta riguarda un token delegato (catena `act` presente). */
    public function isDelegated(): bool
    {
        return $this->actors !== null && $this->actors !== [];
    }

    /**
     * Chiave di cache stabile: la decisione dipende da TUTTI gli input (incl. context ABAC e AAL),
     * quindi vanno tutti nella chiave, altrimenti due query diverse condividerebbero un esito.
     *
     * IAM-23: usa JSON_THROW_ON_ERROR. Il vecchio `(string) json_encode(...)` collassava a "" su un
     * context non serializzabile → la chiave diventava una COSTANTE e un ALLOW cachato per un subject
     * veniva servito a QUALSIASI altro. Su failure lanciamo: il CachingDecider bypassa la cache (mai una
     * chiave che collide tra input diversi).
     *
     * @throws \JsonException se un input del context non è serializzabile
     */
    public function cacheKey(): string
    {
        return hash('sha256', json_encode([
            $this->subjectType, $this->subjectId, $this->permission,
            $this->organization, $this->application, $this->resource,
            $this->context, $this->currentAal,
            $this->actors, $this->delegationGrantId,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $body = [
            'subject' => ['type' => $this->subjectType, 'id' => $this->subjectId],
            'permission' => $this->permission,
            'organization' => $this->organization,
            'application' => $this->application,
            'resource' => $this->resource,
            'context' => $this->context,
            'current_aal' => $this->currentAal,
            'explain' => $this->explain,
        ];
        if ($this->isDelegated()) {
            $body['actors'] = $this->actors;
            if ($this->delegationGrantId !== null && $this->delegationGrantId !== '') {
                $body['delegation_grant_id'] = $this->delegationGrantId;
            }
        }

        return $body;
    }
}
