<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Padosoft\Iam\Client\Auth\TokenProvider;

/**
 * Push a hand-authored manifest file to IAM's Admin API — for apps that do NOT use spatie (the manifest is
 * the declaration you version in your repo). IAM validates + diffs it: additive changes apply, a removal is
 * gated for approval in the console. Authenticates with the client SDK's own bearer (needs iam:manifests.submit).
 * Run it in CI on deploy, or by hand. This is the cross-language equivalent of the spatie bridge's sync.
 */
final class ManifestPushCommand extends Command
{
    protected $signature = 'iam:manifest:push
        {file : path to the manifest JSON file}
        {--app= : app.key (default: the app.key inside the manifest)}';

    protected $description = 'Push a manifest file to IAM (submit it to the Admin API for diff + apply/approval).';

    public function handle(TokenProvider $tokens): int
    {
        $file = $this->argument('file');
        if (!is_string($file) || !is_file($file)) {
            $this->error('File not found: '.(is_string($file) ? $file : ''));

            return self::FAILURE;
        }
        $manifest = json_decode((string) file_get_contents($file), true);
        if (!is_array($manifest)) {
            $this->error('Manifest is not valid JSON.');

            return self::FAILURE;
        }

        $appOpt = $this->option('app');
        $appBlock = is_array($manifest['app'] ?? null) ? $manifest['app'] : [];
        $app = is_string($appOpt) && $appOpt !== ''
            ? $appOpt
            : (is_string($appBlock['key'] ?? null) ? $appBlock['key'] : null);
        if (!is_string($app) || $app === '') {
            $this->error('No app key — pass --app or set app.key in the manifest.');

            return self::FAILURE;
        }

        $base = config('iam-client.http.base_url');
        if (!is_string($base) || $base === '') {
            $this->error('iam-client.http.base_url is not configured.');

            return self::FAILURE;
        }
        $token = $tokens->resolve();
        if ($token === null) {
            $this->error('Could not obtain a bearer token — check the iam-client auth config.');

            return self::FAILURE;
        }

        $res = Http::acceptJson()
            ->withToken($token)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->post(rtrim($base, '/').'/applications/'.rawurlencode($app).'/manifests', ['manifest' => $manifest]);

        if (!$res->successful()) {
            $this->error("Push rejected (HTTP {$res->status()}): ".Str::limit($res->body(), 300));

            return self::FAILURE;
        }

        $data = is_array($res->json('data')) ? $res->json('data') : (array) $res->json();
        $status = is_string($data['status'] ?? null) ? $data['status'] : '?';
        $version = is_scalar($data['version'] ?? null) ? (string) $data['version'] : '?';
        $this->info("Submitted manifest for '{$app}' (version {$version}, status {$status}).");
        if ($status === 'pending_approval') {
            $this->warn('This change needs approval in the console (breaking, e.g. a removed role/permission).');
        }

        return self::SUCCESS;
    }
}
