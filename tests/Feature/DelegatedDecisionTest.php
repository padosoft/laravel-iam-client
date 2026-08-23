<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\Iam\Agents\Models\Agent;
use Padosoft\Iam\Client\Auth\DelegatedTokenVerifier;
use Padosoft\Iam\Client\Contracts\Decider;
use Padosoft\Iam\Client\Deciders\CachingDecider;
use Padosoft\Iam\Client\Deciders\LocalDecider;
use Padosoft\Iam\Client\DecisionRequest;
use Padosoft\Iam\Client\IamClient;
use Padosoft\Iam\Client\IamDecision;
use Padosoft\Iam\Client\Support\DelegatedBearerInspector;
use Padosoft\Iam\Contracts\Authorization\AuthorizationEngine;
use Padosoft\Iam\Contracts\Delegation\AgentStatus;
use Padosoft\Iam\Contracts\Delegation\DelegatedAuthorizationEngine;
use Padosoft\Iam\Domain\Authorization\Models\Grant;
use Padosoft\Iam\Domain\Authorization\Models\Permission;

uses(RefreshDatabase::class);

// ── Helper ──────────────────────────────────────────────────────────────────

/** JWT non firmato (basta al parse locale: l'autorizzazione passa SEMPRE dall'introspection). */
function unsignedJwt(array $claims, array $header = ['alg' => 'ES256', 'typ' => 'JWT']): string
{
    $b64 = static fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return $b64($header).'.'.$b64($claims).'.sig';
}

function delegatedLocalClient(): IamClient
{
    return new IamClient(
        new LocalDecider(app(AuthorizationEngine::class), app(DelegatedAuthorizationEngine::class)),
        config('iam-client'),
    );
}

function seedDelegatedWorld(): Agent
{
    Permission::create(['application_key' => 'shop', 'key' => 'orders.read', 'full_key' => 'shop:orders.read']);

    // Layer UTENTE: usr_1 ha il permesso.
    Grant::create([
        'subject_type' => 'user', 'subject_id' => 'usr_1',
        'privilege_type' => 'permission', 'privilege_key' => 'shop:orders.read',
        'application_key' => null,
    ]);

    // Layer AGENTE: l'agente registrato, attivo e con lo stesso permesso.
    $agent = Agent::query()->create([
        'id' => Agent::newId(), 'name' => 'Copilot',
        'max_scopes' => ['orders:read'], 'status' => AgentStatus::Active->value,
    ]);
    Grant::create([
        'subject_type' => 'agent', 'subject_id' => $agent->id,
        'privilege_type' => 'permission', 'privilege_key' => 'shop:orders.read',
        'application_key' => null,
    ]);

    return $agent;
}

// ── DecisionRequest ─────────────────────────────────────────────────────────

it('actors e grant id entrano nella cache key e nel body (additivo)', function () {
    $plain = new DecisionRequest(permission: 'shop:orders.read', subjectId: 'usr_1');
    $delegated = new DecisionRequest(
        permission: 'shop:orders.read', subjectId: 'usr_1',
        actors: ['agent:agt_1'], delegationGrantId: 'dgr_1',
    );

    expect($plain->isDelegated())->toBeFalse()
        ->and($delegated->isDelegated())->toBeTrue()
        ->and($plain->cacheKey())->not->toBe($delegated->cacheKey())
        ->and($delegated->toArray()['actors'])->toBe(['agent:agt_1'])
        ->and($delegated->toArray()['delegation_grant_id'])->toBe('dgr_1')
        ->and($plain->toArray())->not->toHaveKey('actors');
});

// ── CachingDecider ──────────────────────────────────────────────────────────

it('le decisioni delegate NON si cachano mai (la revoca morde al check successivo)', function () {
    $calls = 0;
    $counting = new class($calls) implements Decider
    {
        public function __construct(private int &$calls) {}

        public function decide(DecisionRequest $request): IamDecision
        {
            $this->calls++;

            return IamDecision::deny('x');
        }
    };
    $cache = new CacheRepository(new ArrayStore);
    $decider = new CachingDecider($counting, $cache, 30, true);

    $delegated = new DecisionRequest(permission: 'p', subjectId: 'u', actors: ['agent:a']);
    $decider->decide($delegated);
    $decider->decide($delegated);
    expect($calls)->toBe(2); // nessuna cache

    $plain = new DecisionRequest(permission: 'p', subjectId: 'u');
    $decider->decide($plain);
    $decider->decide($plain);
    expect($calls)->toBe(3); // la seconda è servita dalla cache
});

// ── LocalDecider + intersezione (modulo -agents reale) ──────────────────────

it('mode=local: la decisione delegata è l\'intersezione utente ∩ agente', function () {
    $agent = seedDelegatedWorld();
    $client = delegatedLocalClient();
    $actors = ['agent:'.$agent->id];

    // Entrambi i layer permettono ⇒ allow.
    expect($client->canDelegated('usr_1', $actors, 'shop:orders.read'))->toBeTrue();

    // Utente senza il permesso ⇒ deny (anche se l'agente lo ha).
    expect($client->canDelegated('usr_2', $actors, 'shop:orders.read'))->toBeFalse();

    // Agente sospeso ⇒ deny (anche se l'utente lo ha).
    $agent->fill(['status' => AgentStatus::Suspended->value])->save();
    expect($client->canDelegated('usr_1', $actors, 'shop:orders.read'))->toBeFalse();
});

it('mode=local SENZA il modulo agents: le richieste delegate NEGANO (fail-closed)', function () {
    seedDelegatedWorld();
    $bare = new IamClient(new LocalDecider(app(AuthorizationEngine::class)), config('iam-client'));

    // Il permesso c'è per entrambi i layer, ma senza DelegatedAuthorizationEngine ⇒ deny.
    expect($bare->canDelegated('usr_1', ['agent:qualunque'], 'shop:orders.read'))->toBeFalse();
});

it('catena senza attori o subject assente ⇒ deny senza toccare il PDP', function () {
    $client = delegatedLocalClient();

    expect($client->canDelegated('usr_1', [], 'shop:orders.read'))->toBeFalse()
        ->and($client->canDelegated(null, ['agent:a'], 'shop:orders.read'))->toBeFalse();
});

// ── DelegatedBearerInspector ────────────────────────────────────────────────

it('riconosce un token delegato da typ o da act e ne estrae la catena', function () {
    $inspector = new DelegatedBearerInspector;

    $byTyp = unsignedJwt(
        ['sub' => 'usr_1', 'act' => ['sub' => 'agent:a1'], 'pds_dgr' => 'dgr_1', 'scope' => 'orders:read'],
        ['alg' => 'ES256', 'typ' => 'delegated+jwt'],
    );
    $parsed = $inspector->inspect($byTyp);
    expect($parsed)->not->toBeNull()
        ->and($parsed->sub)->toBe('usr_1')
        ->and($parsed->actors)->toBe(['agent:a1'])
        ->and($parsed->grantId)->toBe('dgr_1')
        ->and($parsed->scopes)->toBe(['orders:read'])
        ->and($parsed->verified)->toBeFalse();

    // Catena annidata (multi-hop): attore corrente per primo.
    $nested = unsignedJwt(['sub' => 'usr_1', 'act' => ['sub' => 'agent:b', 'act' => ['sub' => 'agent:a']]]);
    expect($inspector->inspect($nested)->actors)->toBe(['agent:b', 'agent:a']);

    // Token normale ⇒ null (procede il flusso classico).
    expect($inspector->inspect(unsignedJwt(['sub' => 'usr_1'])))->toBeNull()
        ->and($inspector->inspect('non-un-jwt'))->toBeNull();
});

it('un token delegato MALFORMATO lancia — mai degradare a token utente pieno', function (array $claims, array $header) {
    expect(fn () => (new DelegatedBearerInspector)->inspect(unsignedJwt($claims, $header)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'typ delegato senza act né sub valido' => [['scope' => 'x'], ['alg' => 'ES256', 'typ' => 'delegated+jwt']],
    'act senza sub agent' => [['sub' => 'usr_1', 'act' => ['sub' => 'user:evil']], ['alg' => 'ES256', 'typ' => 'JWT']],
    'act non oggetto' => [['sub' => 'usr_1', 'act' => 'agent:a'], ['alg' => 'ES256', 'typ' => 'JWT']],
]);

// ── DelegatedTokenVerifier (introspection-mandatory) ────────────────────────

function verifierWith(array $responses, string $url = 'https://iam.test/oauth/introspect'): DelegatedTokenVerifier
{
    $mock = new MockHandler($responses);

    return new DelegatedTokenVerifier(
        new GuzzleClient(['handler' => HandlerStack::create($mock)]),
        new DelegatedBearerInspector,
        $url,
        'cli_app',
        's3cret',
    );
}

it('verifica via introspection: la vista autorizzativa nasce dai claims del server', function () {
    $jwt = unsignedJwt(['sub' => 'usr_LOCALE', 'act' => ['sub' => 'agent:LOCALE']]);

    $verified = verifierWith([new Response(200, [], json_encode([
        'active' => true,
        'sub' => 'usr_VERO',
        'act' => ['sub' => 'agent:VERO'],
        'pds_dgr' => 'dgr_VERO',
        'scope' => 'orders:read',
    ]))])->verify($jwt);

    // I claims INTROSPETTATI vincono su quelli locali (il parse locale è solo instradamento).
    expect($verified)->not->toBeNull()
        ->and($verified->verified)->toBeTrue()
        ->and($verified->sub)->toBe('usr_VERO')
        ->and($verified->actors)->toBe(['agent:VERO'])
        ->and($verified->grantId)->toBe('dgr_VERO');
});

it('introspection inattiva, errore o assente ⇒ null (deny a valle)', function () {
    $jwt = unsignedJwt(['sub' => 'usr_1', 'act' => ['sub' => 'agent:a']]);

    expect(verifierWith([new Response(200, [], '{"active":false}')])->verify($jwt))->toBeNull()
        ->and(verifierWith([new Response(500, [], 'boom')])->verify($jwt))->toBeNull()
        ->and(verifierWith([], '')->verify($jwt))->toBeNull(); // nessun URL configurato
});

it('un token NON delegato non passa dal verifier delegato', function () {
    expect(verifierWith([])->verify(unsignedJwt(['sub' => 'usr_1'])))->toBeNull();
});
