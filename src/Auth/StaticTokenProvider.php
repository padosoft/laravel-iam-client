<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Auth;

/**
 * Token statico: l'app fornisce un service token (es. via IAM_CLIENT_TOKEN) ottenuto fuori banda.
 * Modalità di default, retro-compatibile.
 */
final class StaticTokenProvider implements TokenProvider
{
    public function __construct(private readonly ?string $token) {}

    public function resolve(): ?string
    {
        return $this->token !== null && $this->token !== '' ? $this->token : null;
    }
}
