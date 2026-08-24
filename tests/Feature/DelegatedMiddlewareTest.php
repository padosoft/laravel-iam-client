<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Padosoft\Iam\Agents\Models\Agent;
use Padosoft\Iam\Agents\Models\DelegationGrantModel;
use Padosoft\Iam\Client\Auth\DelegatedTokenVerifier;
use Padosoft\Iam\Client\Http\Middleware\IamCanDelegated;
use Padosoft\Iam\Client\Support\DelegatedBearerInspector;
use Padosoft\Iam\Contracts\Delegation\AgentStatus;
use Padosoft\Iam\Contracts\Delegation\DelegationGrantStatus;
use Padosoft\Iam\Domain\Authorization\Models\Grant;
use Padosoft\Iam\Domain\Authorization\Models\Permission;

uses(RefreshDatabase::class);

// ── Helper (nomi propri: le funzioni Pest sono globali cross-file) ──────────

function mwJwt(array $claims): string
{
    $b64 = static fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return $b64(['alg' => 'ES256', 'typ' => 'delegated+jwt']).'.'.$b64($claims).'.sig';
}

/** Seed dei due layer dell'intersezione: usr_1 e l'agente hanno entrambi il permesso. */
function mwSeed(): Agent
{
    Permission::create(['application_key' => 'shop', 'key' => 'orders.read', 'full_key' => 'shop:orders.read']);
    Grant::create([
        'subject_type' => 'user', 'subject_id' => 'usr_1',
        'privilege_type' => 'permission', 'privilege_key' => 'shop:orders.read',
        'application_key' => null,
    ]);
    $agent = Agent::query()->create([
        'id' => Agent::newId(), 'name' => 'Copilot',
        'max_scopes' => ['orders:read'], 'status' => AgentStatus::Active->value,
    ]);
    Grant::create([
        'subject_type' => 'agent', 'subject_id' => $agent->id,
        'privilege_type' => 'permission', 'privilege_key' => 'shop:orders.read',
        'application_key' => null,
    ]);
    // La grant citata dal claim pds_dgr: senza (o revocata) il PDP nega fail-closed.
    DelegationGrantModel::query()->create([
        'id' => 'dgr_1',
        'user_type' => 'user', 'user_id' => 'usr_1',
        'agent_id' => $agent->id,
        'scopes' => ['orders:read'], 'purpose' => 'test',
        'status' => DelegationGrantStatus::Active->value,
        'expires_at' => now()->addDay(),
    ]);

    return $agent;
}

/** Bind del verifier con la risposta di introspection mockata (la verità server-side). */
function mwBindVerifier(array $introspection): void
{
    app()->instance(DelegatedTokenVerifier::class, new DelegatedTokenVerifier(
        new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode($introspection, JSON_THROW_ON_ERROR)),
        ]))]),
        new DelegatedBearerInspector,
        'https://iam.test/oauth/introspect',
        'cli_app',
        's3cret',
    ));
}

// ── §10: il contesto di delega idrata request attributes E Laravel Context ──

it('iam.can.delegated: sub + catena act + grant id in request attributes e in Context', function () {
    $agent = mwSeed();
    mwBindVerifier([
        'active' => true,
        'sub' => 'usr_1',
        'act' => ['sub' => 'agent:'.$agent->id],
        'pds_dgr' => 'dgr_1',
        'scope' => 'orders:read',
    ]);

    Route::middleware([IamCanDelegated::class.':shop:orders.read'])->get('/_t/delegated', fn (Request $request) => response()->json([
        'attr' => $request->attributes->get('iam_delegation'),
        // §10: qualunque log/job a valle vede lo stesso contesto senza conoscere la delega.
        'ctx' => Context::get('iam_delegation'),
    ]));

    $response = $this->withToken(mwJwt(['sub' => 'usr_1', 'act' => ['sub' => 'agent:'.$agent->id]]))
        ->getJson('/_t/delegated')
        ->assertOk();

    foreach (['attr', 'ctx'] as $key) {
        $response->assertJsonPath("{$key}.sub", 'usr_1')
            ->assertJsonPath("{$key}.actors.0", 'agent:'.$agent->id)
            ->assertJsonPath("{$key}.grant_id", 'dgr_1')
            ->assertJsonPath("{$key}.scopes.0", 'orders:read');
    }
});

it('401 senza bearer; 403 quando il layer utente nega (intersezione)', function () {
    $agent = mwSeed();
    // L'agente ha il permesso, ma il sub introspettato NON ce l'ha ⇒ intersezione nega.
    mwBindVerifier([
        'active' => true,
        'sub' => 'usr_SENZA_PERMESSO',
        'act' => ['sub' => 'agent:'.$agent->id],
    ]);

    Route::middleware([IamCanDelegated::class.':shop:orders.read'])->get('/_t/delegated2', fn () => 'ok');

    $this->getJson('/_t/delegated2')->assertStatus(401);

    $this->withToken(mwJwt(['sub' => 'usr_SENZA_PERMESSO', 'act' => ['sub' => 'agent:'.$agent->id]]))
        ->getJson('/_t/delegated2')
        ->assertStatus(403);
});
