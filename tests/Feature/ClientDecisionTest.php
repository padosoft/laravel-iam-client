<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\Iam\Client\Contracts\Decider;
use Padosoft\Iam\Client\Deciders\CachingDecider;
use Padosoft\Iam\Client\Deciders\HttpDecider;
use Padosoft\Iam\Client\Deciders\LocalDecider;
use Padosoft\Iam\Client\DecisionRequest;
use Padosoft\Iam\Client\IamClient;
use Padosoft\Iam\Client\IamDecision;
use Padosoft\Iam\Contracts\Authorization\AuthorizationEngine;
use Padosoft\Iam\Contracts\Support\SubjectRef;
use Padosoft\Iam\Domain\Authorization\Models\Grant;
use Padosoft\Iam\Domain\Authorization\Models\Permission;

uses(RefreshDatabase::class);

/** Grant globale (application_key null) → matcha qualunque query app. */
function globalGrant(string $fullKey, array $overrides = []): Grant
{
    return Grant::create(array_merge([
        'subject_type' => 'user',
        'subject_id' => 'usr_1',
        'privilege_type' => 'permission',
        'privilege_key' => $fullKey,
        'application_key' => null,
    ], $overrides));
}

function iamTestClient(): IamClient
{
    return new IamClient(
        new LocalDecider(app(AuthorizationEngine::class)),
        config('iam-client'),
    );
}

it('LocalDecider: can() consente con un grant e nega di default (fail-closed)', function () {
    globalGrant('reports:view');

    expect(iamTestClient()->can('usr_1', 'reports:view'))->toBeTrue()
        ->and(iamTestClient()->can('usr_1', 'reports:delete'))->toBeFalse()
        ->and(iamTestClient()->can('usr_999', 'reports:view'))->toBeFalse();
});

it('can() senza subject identificabile nega', function () {
    globalGrant('reports:view');

    expect(iamTestClient()->can(null, 'reports:view'))->toBeFalse();
});

it('granted() è false su un permit che richiede step-up non soddisfatto', function () {
    Permission::create(['app_key' => 'reports', 'key' => 'export', 'full_key' => 'reports:export', 'requires_step_up' => true]);
    globalGrant('reports:export');

    $decision = iamTestClient()->check('usr_1', 'reports:export');

    expect($decision->allowed)->toBeTrue()
        ->and($decision->requiresStepUp)->toBeTrue()
        ->and($decision->granted())->toBeFalse()
        ->and(iamTestClient()->can('usr_1', 'reports:export'))->toBeFalse();
});

it('il context ABAC è passato al PDP (amount <= 500)', function () {
    globalGrant('reports:adjust', ['conditions_json' => ['amount' => ['<=' => 500]]]);

    expect(iamTestClient()->can('usr_1', 'reports:adjust', ['amount' => 300]))->toBeTrue()
        ->and(iamTestClient()->can('usr_1', 'reports:adjust', ['amount' => 900]))->toBeFalse();
});

/** Decider che conta le invocazioni in un contatore esterno. */
function countingDecider(stdClass $counter): Decider
{
    return new class($counter) implements Decider
    {
        public function __construct(private stdClass $counter) {}

        public function decide(DecisionRequest $request): IamDecision
        {
            $this->counter->n++;

            return new IamDecision(allowed: true, decisionId: 'dec_cached');
        }
    };
}

it('CachingDecider serve dalla cache e non richiama il decider interno', function () {
    $counter = new stdClass;
    $counter->n = 0;
    $caching = new CachingDecider(countingDecider($counter), new CacheRepository(new ArrayStore), 30, true);
    $request = new DecisionRequest('reports:view', 'usr_1');

    $first = $caching->decide($request);
    $second = $caching->decide($request);

    expect($first->allowed)->toBeTrue()
        ->and($second->allowed)->toBeTrue()
        ->and($counter->n)->toBe(1); // seconda chiamata dalla cache
});

it('CachingDecider non cacha le query explain', function () {
    $counter = new stdClass;
    $counter->n = 0;
    $caching = new CachingDecider(countingDecider($counter), new CacheRepository(new ArrayStore), 30, true);
    $request = new DecisionRequest('reports:view', 'usr_1', explain: true);

    $caching->decide($request);
    $caching->decide($request);

    expect($counter->n)->toBe(2);
});

it('LocalDecider: un errore del PDP in-process → deny (fail-closed)', function () {
    $engine = new class implements AuthorizationEngine
    {
        public function check(array $query): array
        {
            throw new RuntimeException('boom');
        }

        public function listSubjects(string $relation, string $objectType, string $objectId): iterable
        {
            return [];
        }

        public function listResources(SubjectRef $subject, string $relation): iterable
        {
            return [];
        }
    };

    expect((new LocalDecider($engine))->decide(new DecisionRequest('reports:view', 'usr_1'))->allowed)->toBeFalse();
});

it('HttpDecider: 2xx → decisione; non-2xx/transport → deny (fail-closed)', function () {
    $okMock = new MockHandler([new Response(200, [], (string) json_encode(['allowed' => true, 'decision_id' => 'dec_http']))]);
    $ok = new HttpDecider(new GuzzleClient(['handler' => HandlerStack::create($okMock)]), 'https://iam.example/api/iam/v1', 'tok');

    expect($ok->decide(new DecisionRequest('reports:view', 'usr_1'))->allowed)->toBeTrue();

    $failMock = new MockHandler([new Response(500), new Response(200, [], 'not-json')]);
    $fail = new HttpDecider(new GuzzleClient(['handler' => HandlerStack::create($failMock)]), 'https://iam.example/api/iam/v1', 'tok');

    expect($fail->decide(new DecisionRequest('reports:view', 'usr_1'))->allowed)->toBeFalse()  // http 500
        ->and($fail->decide(new DecisionRequest('reports:view', 'usr_1'))->allowed)->toBeFalse(); // body non valido
});

it('HttpDecider: colpisce la rotta slash /decisions/check (non il colon) e scarta l\'envelope {data}', function () {
    // Il server reale serve `POST {base}/decisions/check` (routes/admin.php, openapi.yaml)
    // e avvolge la risposta in `{ "data": {...} }` (AdminController::ok()). Questo test fissa
    // entrambi i contratti: se qualcuno reintroduce il colon o salta l'unwrap, fallisce.
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], (string) json_encode([
            'data' => ['allowed' => true, 'decision_id' => 'dec_env', 'requires_step_up' => false],
        ])),
    ]));
    $stack->push(Middleware::history($history));

    $decider = new HttpDecider(new GuzzleClient(['handler' => $stack]), 'https://iam.example/api/iam/v1/', 'tok');
    $decision = $decider->decide(new DecisionRequest('reports:view', 'usr_1'));

    // L'envelope `{data}` è scartato → la decisione è letta correttamente.
    expect($decision->allowed)->toBeTrue()
        ->and($decision->decisionId)->toBe('dec_env');

    // L'URL chiamato è la forma slash, non il colon legacy.
    $path = $history[0]['request']->getUri()->getPath();
    expect($path)->toBe('/api/iam/v1/decisions/check')
        ->and($path)->not->toContain('decisions:check');
});
