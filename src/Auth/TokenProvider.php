<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Auth;

/**
 * Fornisce il Bearer usato dall'HttpDecider per l'Admin API (doc 06/13). Due implementazioni: un token
 * statico (fornito dall'app) o client_credentials auto-gestito (client_id + client_secret → token, con
 * auto-rotazione del secret via self-fetch). Fail-closed: se non c'è un token valido, resolve() torna
 * null → nessun header Authorization → il PDP nega.
 */
interface TokenProvider
{
    public function resolve(): ?string;
}
