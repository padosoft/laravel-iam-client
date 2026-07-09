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
use Illuminate\Support\Facades\Crypt;
use Padosoft\Iam\Client\Auth\ClientCredentialsTokenProvider;
use Padosoft\Iam\Client\Auth\PrivateKeyJwtTokenProvider;
use Padosoft\Iam\Client\Auth\StaticTokenProvider;
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
    $ok = new HttpDecider(new GuzzleClient(['handler' => HandlerStack::create($okMock)]), 'https://iam.example/api/iam/v1', new StaticTokenProvider('tok'));

    expect($ok->decide(new DecisionRequest('reports:view', 'usr_1'))->allowed)->toBeTrue();

    $failMock = new MockHandler([new Response(500), new Response(200, [], 'not-json')]);
    $fail = new HttpDecider(new GuzzleClient(['handler' => HandlerStack::create($failMock)]), 'https://iam.example/api/iam/v1', new StaticTokenProvider('tok'));

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

    $decider = new HttpDecider(new GuzzleClient(['handler' => $stack]), 'https://iam.example/api/iam/v1/', new StaticTokenProvider('tok'));
    $decision = $decider->decide(new DecisionRequest('reports:view', 'usr_1'));

    // L'envelope `{data}` è scartato → la decisione è letta correttamente.
    expect($decision->allowed)->toBeTrue()
        ->and($decision->decisionId)->toBe('dec_env');

    // L'URL chiamato è la forma slash, non il colon legacy.
    $path = $history[0]['request']->getUri()->getPath();
    expect($path)->toBe('/api/iam/v1/decisions/check')
        ->and($path)->not->toContain('decisions:check');
});

it('ClientCredentialsTokenProvider: ottiene e cacha il token via client_credentials', function () {
    $mock = new MockHandler([new Response(200, [], (string) json_encode(['access_token' => 'AT1', 'expires_in' => 900]))]);
    $cache = new CacheRepository(new ArrayStore);
    $p = new ClientCredentialsTokenProvider(new GuzzleClient(['handler' => HandlerStack::create($mock)]), 'https://iam.example/oauth', 'cli_x', 'sec', $cache);

    // Prima chiamata: mint; seconda: dalla cache (il mock ha una sola risposta, quindi se non cachasse fallirebbe).
    expect($p->resolve())->toBe('AT1')
        ->and($p->resolve())->toBe('AT1');
});

it('ClientCredentialsTokenProvider: su 401 auto-ritira il secret ruotato e riprova (rollover trasparente)', function () {
    $mock = new MockHandler([
        new Response(401, [], 'invalid_client'),                                                        // token col secret vecchio
        new Response(200, [], (string) json_encode(['rotated' => true, 'client_secret' => 'NEW'])),      // self-fetch del nuovo
        new Response(200, [], (string) json_encode(['access_token' => 'AT2', 'expires_in' => 900])),     // retry col nuovo
    ]);
    $cache = new CacheRepository(new ArrayStore);
    $p = new ClientCredentialsTokenProvider(new GuzzleClient(['handler' => HandlerStack::create($mock)]), 'https://iam.example/oauth', 'cli_x', 'OLD', $cache);

    expect($p->resolve())->toBe('AT2');
    // IAM-25: il secret ruotato è cifrato at-rest in cache (mai in chiaro) → si verifica decifrandolo.
    $stored = $cache->get('iam-client:cc-secret:'.sha1('cli_x|https://iam.example/oauth'));
    expect($stored)->not->toBe('NEW') // non è più in chiaro
        ->and(Crypt::decryptString($stored))->toBe('NEW');
});

it('ClientCredentialsTokenProvider: un oauth_url http:// (non-localhost) è fail-closed → null (IAM-39)', function () {
    $mock = new MockHandler([new Response(200, [], (string) json_encode(['access_token' => 'AT', 'expires_in' => 900]))]);
    $p = new ClientCredentialsTokenProvider(new GuzzleClient(['handler' => HandlerStack::create($mock)]), 'http://iam.example/oauth', 'cli_x', 'sec', new CacheRepository(new ArrayStore));

    expect($p->resolve())->toBeNull();
});

it('ClientCredentialsTokenProvider: un secret legacy in CHIARO in cache è usato e migrato a cifrato (IAM-25 backward-compat)', function () {
    // Simula una release precedente: il secret ruotato era cachato in CHIARO (cache->forever).
    $cache = new CacheRepository(new ArrayStore);
    $key = 'iam-client:cc-secret:'.sha1('cli_x|https://iam.example/oauth');
    $cache->forever($key, 'LEGACY');

    $mock = new MockHandler([new Response(200, [], (string) json_encode(['access_token' => 'AT', 'expires_in' => 900]))]);
    $p = new ClientCredentialsTokenProvider(new GuzzleClient(['handler' => HandlerStack::create($mock)]), 'https://iam.example/oauth', 'cli_x', 'CONFIG', $cache);

    // Il valore legacy NON viene scartato (niente regressione a fail-closed durante il rollover).
    expect($p->resolve())->toBe('AT');
    // ...ed è stato migrato: ora è cifrato at-rest e decifra al valore legacy.
    $stored = $cache->get($key);
    expect($stored)->not->toBe('LEGACY')
        ->and(Crypt::decryptString($stored))->toBe('LEGACY');
});

it('ClientCredentialsTokenProvider: se token e self-fetch falliscono, resolve() è null (fail-closed)', function () {
    $mock = new MockHandler([new Response(500), new Response(500)]);
    $p = new ClientCredentialsTokenProvider(new GuzzleClient(['handler' => HandlerStack::create($mock)]), 'https://iam.example/oauth', 'cli_x', 'sec', new CacheRepository(new ArrayStore));

    expect($p->resolve())->toBeNull();
});

it('HttpDecider: accetta ancora un token stringa (retro-compat) oltre al TokenProvider', function () {
    $mock = new MockHandler([new Response(200, [], (string) json_encode(['allowed' => true, 'decision_id' => 'dec_bc']))]);
    // Terzo argomento come stringa grezza (API v1.1) — deve continuare a funzionare, avvolto in StaticTokenProvider.
    $decider = new HttpDecider(new GuzzleClient(['handler' => HandlerStack::create($mock)]), 'https://iam.example/api/iam/v1', 'raw-token');

    expect($decider->decide(new DecisionRequest('reports:view', 'usr_1'))->allowed)->toBeTrue();
});

it('PrivateKeyJwtTokenProvider: firma un assertion ES256 e ottiene il token (nessun secret condiviso)', function () {
    $cnf = sys_get_temp_dir().DIRECTORY_SEPARATOR.'iam-client-openssl.cnf';
    if (!is_file($cnf)) {
        file_put_contents($cnf, "[req]\n");
    }
    $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'private_key_bits' => 2048, 'config' => $cnf]);
    openssl_pkey_export($res, $pem, null, ['config' => $cnf]);

    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], (string) json_encode(['access_token' => 'AT-PK', 'expires_in' => 900]))]));
    $stack->push(Middleware::history($history));
    $cache = new CacheRepository(new ArrayStore);

    $p = new PrivateKeyJwtTokenProvider(new GuzzleClient(['handler' => $stack]), 'https://iam.example/oauth', 'cli_pk', $pem, 'k1', $cache);

    expect($p->resolve())->toBe('AT-PK')
        ->and($p->resolve())->toBe('AT-PK'); // seconda volta dalla cache (una sola risposta mock)

    // La richiesta ha usato un client_assertion (JWT a 3 segmenti), NON un client_secret.
    parse_str((string) $history[0]['request']->getBody(), $params);
    expect($params['client_assertion_type'])->toBe('urn:ietf:params:oauth:client-assertion-type:jwt-bearer')
        ->and(substr_count((string) $params['client_assertion'], '.'))->toBe(2)
        ->and($params)->not->toHaveKey('client_secret');
});

it('HttpDecider: un base_url http:// (non-loopback) è fail-closed → deny SENZA spedire il Bearer (IAM-39b)', function () {
    // La decision call porta il Bearer verso base_url: su http:// non-loopback si nega PRIMA di chiamare,
    // così il token non viaggia mai in chiaro. La history resta vuota (nessuna richiesta partita).
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], (string) json_encode(['allowed' => true]))]));
    $stack->push(Middleware::history($history));
    $decider = new HttpDecider(new GuzzleClient(['handler' => $stack]), 'http://iam.example/api/iam/v1', new StaticTokenProvider('tok'));

    expect($decider->decide(new DecisionRequest('reports:view', 'usr_1'))->allowed)->toBeFalse()
        ->and($history)->toBeEmpty(); // guardia scattata: nessuna richiesta HTTP
});

it('HttpDecider: http://localhost è ammesso (dev loopback) — la decisione parte (IAM-39b)', function () {
    $mock = new MockHandler([new Response(200, [], (string) json_encode(['allowed' => true, 'decision_id' => 'dec_lo']))]);
    $decider = new HttpDecider(new GuzzleClient(['handler' => HandlerStack::create($mock)]), 'http://localhost:8080/api/iam/v1', new StaticTokenProvider('tok'));

    expect($decider->decide(new DecisionRequest('reports:view', 'usr_1'))->allowed)->toBeTrue();
});

it('HttpDecider: http:// con allow_insecure=true è ammesso (opt-in dev) (IAM-39b)', function () {
    $mock = new MockHandler([new Response(200, [], (string) json_encode(['allowed' => true, 'decision_id' => 'dec_ins']))]);
    $decider = new HttpDecider(new GuzzleClient(['handler' => HandlerStack::create($mock)]), 'http://iam.example/api/iam/v1', new StaticTokenProvider('tok'), true);

    expect($decider->decide(new DecisionRequest('reports:view', 'usr_1'))->allowed)->toBeTrue();
});

it('PrivateKeyJwtTokenProvider: un oauth_url http:// (non-loopback) è fail-closed → null, nessuna assertion spedita (IAM-39b)', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], (string) json_encode(['access_token' => 'AT', 'expires_in' => 900]))]));
    $stack->push(Middleware::history($history));
    // PEM fittizio: la guardia nega PRIMA di firmare/spedire, quindi non serve una chiave valida.
    $p = new PrivateKeyJwtTokenProvider(new GuzzleClient(['handler' => $stack]), 'http://iam.example/oauth', 'cli_pk', 'dummy-pem', 'k1', new CacheRepository(new ArrayStore));

    expect($p->resolve())->toBeNull()
        ->and($history)->toBeEmpty();
});
