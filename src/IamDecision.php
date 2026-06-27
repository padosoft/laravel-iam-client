<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client;

/**
 * Esito di decisione lato client, normalizzato dalla risposta del PDP (Decision::toArray, doc 09
 * §8). `allowed` da solo NON basta per consentire un'azione: se `requiresStepUp` è true l'accesso
 * è permesso SOLO con un AAL più alto → un gate ingenuo deve trattarlo come non-ancora-consentito.
 */
final readonly class IamDecision
{
    /** @param list<string> $explanation */
    public function __construct(
        public bool $allowed,
        public string $decisionId = '',
        public int $policyVersion = 0,
        public bool $requiresStepUp = false,
        public ?string $requiredAal = null,
        public array $explanation = [],
    ) {}

    /** Decisione di rifiuto esplicita (fail-closed): nessun subject, errore di trasporto, ecc. */
    public static function deny(string $reason): self
    {
        return new self(allowed: false, explanation: [$reason]);
    }

    /** @param array<array-key, mixed> $data Decision::toArray() dal PDP (locale o via Admin API) */
    public static function fromArray(array $data): self
    {
        $explanation = $data['explanation'] ?? [];

        return new self(
            allowed: ($data['allowed'] ?? false) === true,
            decisionId: is_string($data['decision_id'] ?? null) ? $data['decision_id'] : '',
            policyVersion: is_int($data['policy_version'] ?? null) ? $data['policy_version'] : 0,
            requiresStepUp: ($data['requires_step_up'] ?? false) === true,
            requiredAal: is_string($data['required_aal'] ?? null) ? $data['required_aal'] : null,
            explanation: is_array($explanation) ? array_values(array_filter($explanation, 'is_string')) : [],
        );
    }

    /**
     * Consentito davvero: permit del PDP E nessuno step-up pendente. È l'interpretazione fail-safe
     * usata da gate/middleware; chi gestisce lo step-up può ispezionare `requiresStepUp`.
     */
    public function granted(): bool
    {
        return $this->allowed && !$this->requiresStepUp;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'granted' => $this->granted(),
            'decision_id' => $this->decisionId,
            'policy_version' => $this->policyVersion,
            'requires_step_up' => $this->requiresStepUp,
            'required_aal' => $this->requiredAal,
            'explanation' => $this->explanation,
        ];
    }
}
