<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use LogicException;

final class DatabaseSafety
{
    public static function ensureSafe(Application $app): void
    {
        $connection = $app['config']->get('database.connections.sqlite', []);

        if (! $app->environment('testing')
            || $app['config']->get('database.default') !== 'sqlite'
            || ($connection['driver'] ?? null) !== 'sqlite'
            || ($connection['database'] ?? null) !== ':memory:'
            || ! empty($connection['url'])
            || ! empty($connection['read'])
            || ! empty($connection['write'])) {
            throw new LogicException('Tests require the testing environment and SQLite :memory: without URL or read/write overrides.');
        }
    }
}
