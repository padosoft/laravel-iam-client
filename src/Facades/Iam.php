<?php

declare(strict_types=1);

namespace Padosoft\Iam\Client\Facades;

use Illuminate\Support\Facades\Facade;
use Padosoft\Iam\Client\IamClient;

/**
 * Facade `Iam` (doc 06): `Iam::can($user, 'warehouse:stock.adjust', ['amount' => 300])`.
 *
 * @method static bool can(\Illuminate\Contracts\Auth\Authenticatable|string|null $user, string $ability, array<string, mixed> $context = [])
 * @method static bool denies(\Illuminate\Contracts\Auth\Authenticatable|string|null $user, string $ability, array<string, mixed> $context = [])
 * @method static \Padosoft\Iam\Client\IamDecision check(\Illuminate\Contracts\Auth\Authenticatable|string|null $user, string $ability, array<string, mixed> $context = [])
 *
 * @see IamClient
 */
final class Iam extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return IamClient::class;
    }
}
