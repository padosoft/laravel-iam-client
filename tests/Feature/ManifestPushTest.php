<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

it('iam:manifest:push submits a manifest file to the Admin API with the bearer + idempotency key', function () {
    config(['iam-client.http.base_url' => 'https://iam.test/api/iam/v1', 'iam-client.http.token' => 'svc-token']);
    Http::fake(['https://iam.test/*' => Http::response(['data' => ['version' => 3, 'status' => 'approved']], 201)]);

    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'m-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($path, (string) json_encode([
        'schema' => 'laravel-iam.manifest.v2',
        'app' => ['key' => 'shop', 'name' => 'Shop'],
        'permissions' => [], 'roles' => [],
    ]));

    $this->artisan('iam:manifest:push', ['file' => $path])->assertExitCode(0);
    @unlink($path);

    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/applications/shop/manifests')
        && $req->hasHeader('Authorization', 'Bearer svc-token')
        && $req->hasHeader('Idempotency-Key')
        && (($req['manifest']['app']['key'] ?? null) === 'shop'));
});

it('iam:manifest:push fails on a missing file', function () {
    config(['iam-client.http.base_url' => 'https://iam.test/api/iam/v1', 'iam-client.http.token' => 'svc-token']);
    $this->artisan('iam:manifest:push', ['file' => 'does-not-exist.json'])->assertExitCode(1);
});

it('iam:manifest:push refuses an http:// base_url — fail-closed, nothing sent (IAM-39b)', function () {
    config(['iam-client.http.base_url' => 'http://iam.test/api/iam/v1', 'iam-client.http.token' => 'svc-token']);
    Http::fake(); // registra qualunque richiesta partisse

    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'m-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($path, (string) json_encode(['app' => ['key' => 'shop', 'name' => 'Shop']]));

    $this->artisan('iam:manifest:push', ['file' => $path])->assertExitCode(1);
    @unlink($path);

    Http::assertNothingSent(); // il Bearer non è mai partito verso l'endpoint http://
});
