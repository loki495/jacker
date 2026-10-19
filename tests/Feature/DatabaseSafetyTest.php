<?php

declare(strict_types=1);

use Tests\Support\DatabaseSafety;

it('allows only the isolated in-memory test database', function (): void {
    expect(fn () => DatabaseSafety::ensureSafe($this->app))->not->toThrow(LogicException::class);
});

it('refuses unsafe database settings before migrations can run', function (string $key, mixed $value): void {
    config()->set($key, $value);

    expect(fn () => DatabaseSafety::ensureSafe($this->app))->toThrow(LogicException::class);
})->with([
    'real SQLite file' => ['database.connections.sqlite.database', '/var/www/html/database/database.sqlite'],
    'another default connection' => ['database.default', 'mysql'],
    'database URL override' => ['database.connections.sqlite.url', 'sqlite:///real.sqlite'],
    'read connection override' => ['database.connections.sqlite.read', ['database' => '/real.sqlite']],
    'write connection override' => ['database.connections.sqlite.write', ['database' => '/real.sqlite']],
]);

it('refuses non-testing environments', function (): void {
    $this->app->instance('env', 'production');

    expect(fn () => DatabaseSafety::ensureSafe($this->app))->toThrow(LogicException::class);
});
