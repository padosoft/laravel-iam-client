<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Auth;

/**
 * Fallimento del Token Exchange (RFC 8693): il contratto TokenExchanger THROWA, mai
 * un token degradato. `error` è il codice RFC 6749/8693 restituito dal server —
 * `invalid_grant` (grant assente/revocata, agente non attivo, sessione utente morta)
 * e `invalid_scope` (richiesta fuori intersezione) sono i due che un runtime agente
 * deve saper distinguere (es. flow-ai mappa invalid_grant → GrantRevokedException).
 */
final class TokenExchangeFailedException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $error = null,
        public readonly ?string $errorDescription = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self(
            'Token exchange not configured: set iam-client.http.client_id and http.private_key (private_key_jwt is the only client auth agents are allowed).',
            'not_configured',
        );
    }

    public static function insecureTransport(string $url): self
    {
        return new self(
            "Refusing token exchange over insecure transport: {$url} (subject token and signed assertion would travel in cleartext).",
            'insecure_transport',
        );
    }

    public static function transport(\Throwable $previous): self
    {
        return new self('Token exchange transport failure: '.$previous->getMessage(), 'transport_error', null, $previous);
    }

    /** @param  array<array-key, mixed>|null  $body */
    public static function fromErrorResponse(int $status, ?array $body): self
    {
        $error = is_string($body['error'] ?? null) ? $body['error'] : null;
        $description = is_string($body['error_description'] ?? null) ? $body['error_description'] : null;

        return new self(
            'Token exchange refused (HTTP '.$status.'): '.($error ?? 'unknown_error').($description !== null ? " — {$description}" : ''),
            $error,
            $description,
        );
    }

    public static function malformedResponse(): self
    {
        return new self('Token exchange returned a malformed success response (missing access_token/issued_token_type).', 'malformed_response');
    }
}
