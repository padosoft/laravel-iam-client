<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Padosoft\Iam\Client\Auth\ClientAssertion;
use Padosoft\Iam\Client\Auth\HttpTokenExchanger;
use Padosoft\Iam\Client\Auth\TokenExchangeFailedException;
use Padosoft\Iam\Contracts\Delegation\ActClaim;
use Padosoft\Iam\Contracts\Delegation\TokenExchanger;
use Padosoft\Iam\Contracts\Delegation\TokenExchangeRequest;
use Psr\Http\Message\RequestInterface;

// ── Helper ──────────────────────────────────────────────────────────────────

/** Chiave EC P-256 usa-e-getta per firmare le assertion nei test. */
function ecKeyPem(): string
{
    static $pem = null;
    if ($pem === null) {
        $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($res, $out);
        $pem = $out;
    }

    return $pem;
}

/**
 * @param  list<Response>  $responses
 * @param  array<int, array{request: RequestInterface}>  $history  (by-ref)
 */
function exchanger(array $responses, array &$history, ?string $clientId = 'agt_client', ?string $key = null, string $url = 'https://iam.test/oauth'): HttpTokenExchanger
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return new HttpTokenExchanger(
        new GuzzleClient(['handler' => $stack]),
        $url,
        $clientId,
        $key ?? ecKeyPem(),
        'kid-1',
    );
}

/** @return array<string, string> form params dell'ultima richiesta registrata */
function sentForm(array $history): array
{
    parse_str((string) $history[0]['request']->getBody(), $form);

    return $form;
}

function okExchangeResponse(): Response
{
    return new Response(200, [], json_encode([
        'access_token' => 'delegated.jwt.here',
        'issued_token_type' => ActClaim::TOKEN_TYPE_ACCESS,
        'token_type' => 'Bearer',
        'expires_in' => 300,
        'scope' => 'orders:read orders:draft',
    ], JSON_THROW_ON_ERROR));
}

// ── Wire conformance (RFC 8693 §2.1) ────────────────────────────────────────

it('spedisce i parametri wire RFC 8693 e si autentica via private_key_jwt', function () {
    $history = [];
    $result = exchanger([okExchangeResponse()], $history)->exchange(new TokenExchangeRequest(
        subjectToken: 'user.subject.jwt',
        scopes: ['orders:read', 'orders:draft'],
        audience: 'mcp://crm-tools',
    ));

    $req = $history[0]['request'];
    expect((string) $req->getUri())->toBe('https://iam.test/oauth/token');

    $form = sentForm($history);
    expect($form['grant_type'])->toBe(ActClaim::GRANT_TYPE_TOKEN_EXCHANGE)
        ->and($form['subject_token'])->toBe('user.subject.jwt')
        ->and($form['subject_token_type'])->toBe(ActClaim::TOKEN_TYPE_ACCESS)
        ->and($form['requested_token_type'])->toBe(ActClaim::TOKEN_TYPE_ACCESS)
        ->and($form['scope'])->toBe('orders:read orders:draft')
        ->and($form['audience'])->toBe('mcp://crm-tools')
        ->and($form)->not->toHaveKey('resource')
        ->and($form)->not->toHaveKey('actor_token')
        ->and($form['client_assertion_type'])->toBe(ClientAssertion::TYPE);

    // L'assertion è un ES256 con iss=sub=client_id e aud=token endpoint.
    [, $payload] = explode('.', $form['client_assertion']);
    $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    expect($claims['iss'])->toBe('agt_client')
        ->and($claims['sub'])->toBe('agt_client')
        ->and($claims['aud'])->toContain('https://iam.test/oauth/token');

    // Il risultato riflette la risposta del server.
    expect($result->accessToken)->toBe('delegated.jwt.here')
        ->and($result->issuedTokenType)->toBe(ActClaim::TOKEN_TYPE_ACCESS)
        ->and($result->expiresIn)->toBe(300)
        ->and($result->scopes)->toBe(['orders:read', 'orders:draft'])
        ->and($result->tokenType)->toBe('Bearer');
});

it('scope vuoto = omesso (tutti gli scope della grant); actor_token inoltrato se impostato', function () {
    $history = [];
    exchanger([okExchangeResponse()], $history)->exchange(new TokenExchangeRequest(
        subjectToken: 'user.jwt',
        actorToken: 'already.delegated.jwt',
    ));

    $form = sentForm($history);
    expect($form)->not->toHaveKey('scope')
        ->and($form['actor_token'])->toBe('already.delegated.jwt')
        ->and($form['actor_token_type'])->toBe(ActClaim::TOKEN_TYPE_ACCESS);
});

// ── Fallimenti: eccezioni, mai token degradati ──────────────────────────────

it('errore RFC del server ⇒ eccezione con codice distinguibile (invalid_grant vs invalid_scope)', function () {
    $history = [];
    $x = exchanger([new Response(400, [], json_encode([
        'error' => 'invalid_grant',
        'error_description' => 'Delegation grant revoked.',
    ]))], $history);

    try {
        $x->exchange(new TokenExchangeRequest(subjectToken: 'user.jwt'));
        $this->fail('Expected TokenExchangeFailedException');
    } catch (TokenExchangeFailedException $e) {
        expect($e->error)->toBe('invalid_grant')
            ->and($e->errorDescription)->toBe('Delegation grant revoked.')
            ->and($e->getMessage())->toContain('invalid_grant');
    }
});

it('risposta 200 malformata ⇒ eccezione (mai un token vuoto)', function () {
    $history = [];
    $x = exchanger([new Response(200, [], '{"token_type":"Bearer"}')], $history);

    expect(fn () => $x->exchange(new TokenExchangeRequest(subjectToken: 'user.jwt')))
        ->toThrow(TokenExchangeFailedException::class, 'malformed');
});

it('trasporto insicuro ⇒ eccezione PRIMA di spedire subject token e assertion', function () {
    $history = [];
    $x = exchanger([okExchangeResponse()], $history, url: 'http://iam.evil/oauth');

    expect(fn () => $x->exchange(new TokenExchangeRequest(subjectToken: 'user.jwt')))
        ->toThrow(TokenExchangeFailedException::class, 'insecure')
        ->and($history)->toBe([]); // nessuna richiesta partita
});

it('non configurato (niente client_id/private key) ⇒ not_configured', function () {
    $history = [];
    $x = exchanger([okExchangeResponse()], $history, clientId: null);

    expect(fn () => $x->exchange(new TokenExchangeRequest(subjectToken: 'user.jwt')))
        ->toThrow(TokenExchangeFailedException::class, 'not configured');
});

// ── Wiring container ────────────────────────────────────────────────────────

it('il container risolve TokenExchanger (contratto) → HttpTokenExchanger', function () {
    expect(app(TokenExchanger::class))->toBeInstanceOf(HttpTokenExchanger::class);
});
