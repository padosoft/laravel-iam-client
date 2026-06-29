<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Padosoft\Iam\Client\Http\Middleware\IamAuthenticate;
use Padosoft\Iam\Client\Http\Middleware\IamCan;
use Padosoft\Iam\Domain\Authorization\Models\Grant;

uses(RefreshDatabase::class);

function grantGlobal(string $fullKey): Grant
{
    return Grant::create([
        'subject_type' => 'user',
        'subject_id' => 'usr_1',
        'privilege_type' => 'permission',
        'privilege_key' => $fullKey,
        'application_key' => null,
    ]);
}

it('Gate adapter delega a IAM le ability namespaced', function () {
    grantGlobal('reports:view');
    $user = new GenericUser(['id' => 'usr_1']);

    expect(Gate::forUser($user)->allows('reports:view'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('reports:delete'))->toBeFalse();
});

it('Gate adapter NON intercetta le ability non-namespaced (Gate locali invariate)', function () {
    Gate::define('edit-post', fn () => true);
    $user = new GenericUser(['id' => 'usr_1']);

    expect(Gate::forUser($user)->allows('edit-post'))->toBeTrue();
});

it('middleware iam.can: 401 senza utente, 403 se IAM nega, 200 se consente', function () {
    grantGlobal('reports:view');
    // Classe esplicita: nel monorepo l'alias `iam.can` è quello admin del server; il middleware del
    // client si referenzia per classe (in un'app consumer l'alias `iam.can` è invece il nostro).
    Route::middleware([IamCan::class.':reports:view'])->get('/_t/reports', fn () => 'ok');

    $this->get('/_t/reports')->assertStatus(401);
    $this->actingAs(new GenericUser(['id' => 'usr_999']))->get('/_t/reports')->assertStatus(403);
    $this->actingAs(new GenericUser(['id' => 'usr_1']))->get('/_t/reports')->assertStatus(200);
});

it('iam.can lega la decisione a una risorsa dalla route (resource scoping)', function () {
    Grant::create([
        'subject_type' => 'user', 'subject_id' => 'usr_1',
        'privilege_type' => 'permission', 'privilege_key' => 'wh:open',
        'application_key' => null, 'resource_ref' => 'wh_milan',
    ]);
    Route::middleware([IamCan::class.':wh:open,wh'])->get('/_t/wh/{wh}', fn () => 'ok');

    // Grant ristretto a wh_milan: passa solo sulla risorsa corretta, 403 sulle altre.
    $this->actingAs(new GenericUser(['id' => 'usr_1']))->get('/_t/wh/wh_milan')->assertStatus(200);
    $this->actingAs(new GenericUser(['id' => 'usr_1']))->get('/_t/wh/wh_rome')->assertStatus(403);
});

it('middleware iam.auth: 401 senza utente, passa con utente', function () {
    Route::middleware([IamAuthenticate::class])->get('/_t/secure', fn () => 'ok');

    $this->get('/_t/secure')->assertStatus(401);
    $this->actingAs(new GenericUser(['id' => 'usr_1']))->get('/_t/secure')->assertStatus(200);
});
