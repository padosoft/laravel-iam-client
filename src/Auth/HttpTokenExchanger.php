<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Auth;

use GuzzleHttp\ClientInterface;
use Padosoft\Iam\Client\Support\TransportGuard;
use Padosoft\Iam\Contracts\Delegation\ActClaim;
use Padosoft\Iam\Contracts\Delegation\TokenExchanger;
use Padosoft\Iam\Contracts\Delegation\TokenExchangeRequest;
use Padosoft\Iam\Contracts\Delegation\TokenExchangeResult;

/**
 * Lato client del Token Exchange (RFC 8693 §2.1) contro il token endpoint IAM: il
 * chiamante (orchestratore backend, runtime flow-ai) presenta il token dell'UTENTE
 * come subject_token e si autentica come AGENTE via private_key_jwt (RFC 7523) —
 * l'unica client auth ammessa per gli agenti, nessun secret condiviso.
 *
 * DELIBERATAMENTE senza cache: i token delegati sono a vita breve e non-refreshable
 * by design — il ri-exchange È il check di freshness della revoca (grant + sessione
 * ri-verificati dal server a ogni chiamata). Cachare qui allungherebbe la finestra
 * di staleness che il TTL corto esiste per chiudere.
 *
 * Fail-closed con ECCEZIONI (mai un token degradato): il chiamante distingue
 * `invalid_grant` (revoca/sospensione/sessione morta) da `invalid_scope`
 * (fuori intersezione) leggendo TokenExchangeFailedException::$error.
 */
final class HttpTokenExchanger implements TokenExchanger
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $oauthUrl,   // es. https://iam.example.com/oauth
        private readonly ?string $clientId,
        private readonly ?string $privateKeyPem,
        private readonly ?string $kid,
        private readonly int $assertionTtl = 60,
        private readonly bool $allowInsecure = false,
    ) {}

    public function exchange(TokenExchangeRequest $request): TokenExchangeResult
    {
        if ($this->clientId === null || $this->clientId === '' || $this->privateKeyPem === null || $this->privateKeyPem === '') {
            throw TokenExchangeFailedException::notConfigured();
        }
        if ($this->oauthUrl === '' || !TransportGuard::allows($this->oauthUrl, $this->allowInsecure)) {
            throw TokenExchangeFailedException::insecureTransport($this->oauthUrl);
        }

        $endpoint = rtrim($this->oauthUrl, '/').'/token';

        try {
            $response = $this->http->request('POST', $endpoint, [
                'headers' => ['Accept' => 'application/json'],
                'form_params' => $this->formParams($request, $endpoint),
                'http_errors' => false,
                // Mai seguire un 30x https→http: ricocherebbe subject token e assertion in chiaro.
                'allow_redirects' => false,
            ]);
        } catch (\Throwable $e) {
            throw TokenExchangeFailedException::transport($e);
        }

        $body = json_decode((string) $response->getBody(), true);
        $body = is_array($body) ? $body : null;

        if ($response->getStatusCode() !== 200) {
            throw TokenExchangeFailedException::fromErrorResponse($response->getStatusCode(), $body);
        }

        $accessToken = $body['access_token'] ?? null;
        $issuedTokenType = $body['issued_token_type'] ?? null;
        if (!is_string($accessToken) || $accessToken === '' || !is_string($issuedTokenType) || $issuedTokenType === '') {
            throw TokenExchangeFailedException::malformedResponse();
        }

        $scope = $body['scope'] ?? null;
        $tokenType = $body['token_type'] ?? null;

        return new TokenExchangeResult(
            accessToken: $accessToken,
            issuedTokenType: $issuedTokenType,
            expiresIn: is_numeric($body['expires_in'] ?? null) ? (int) $body['expires_in'] : 0,
            scopes: is_string($scope) && $scope !== '' ? array_values(array_filter(explode(' ', $scope))) : [],
            tokenType: is_string($tokenType) && $tokenType !== '' ? $tokenType : 'Bearer',
        );
    }

    /**
     * Parametri wire RFC 8693 §2.1. `actor_token` viene INOLTRATO se il chiamante lo
     * imposta (conformance wire): in MVP è il server a rifiutarlo con invalid_request
     * pulito — la profondità della catena è policy dell'AS, non dell'SDK.
     *
     * @return array<string, string>
     */
    private function formParams(TokenExchangeRequest $request, string $endpoint): array
    {
        $params = [
            'grant_type' => ActClaim::GRANT_TYPE_TOKEN_EXCHANGE,
            'subject_token' => $request->subjectToken,
            'subject_token_type' => $request->subjectTokenType,
            'requested_token_type' => $request->requestedTokenType,
            'client_assertion_type' => ClientAssertion::TYPE,
            'client_assertion' => ClientAssertion::build(
                (string) $this->clientId,
                (string) $this->privateKeyPem,
                $endpoint,
                $this->kid,
                $this->assertionTtl,
            ),
        ];

        if ($request->scopes !== []) {
            $params['scope'] = implode(' ', $request->scopes);
        }
        if ($request->audience !== null && $request->audience !== '') {
            $params['audience'] = $request->audience;
        }
        if ($request->resource !== null && $request->resource !== '') {
            $params['resource'] = $request->resource;
        }
        if ($request->actorToken !== null && $request->actorToken !== '') {
            $params['actor_token'] = $request->actorToken;
            $params['actor_token_type'] = $request->actorTokenType ?? ActClaim::TOKEN_TYPE_ACCESS;
        }

        return $params;
    }
}
