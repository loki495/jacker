<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('checks database safety before RefreshDatabase starts', function (bool $unsafe, string $expected): void {
    $database = sys_get_temp_dir().'/jacker-unsafe-test-'.bin2hex(random_bytes(12)).'.sqlite';
    expect(file_exists($database))->toBeFalse();

    $script = <<<'PHP'
    require 'vendor/autoload.php';

    $test = new class('bootTestEnvironment') extends Tests\TestCase
    {
        use Illuminate\Foundation\Testing\RefreshDatabase;

        public function bootTestEnvironment(): void
        {
            parent::setUp();
        }

        protected function beforeRefreshingDatabase(): void
        {
            // Stop before any connection or migration, even if the production guard regresses.
            throw new RuntimeException('RefreshDatabase started');
        }
    };

    try {
        $test->bootTestEnvironment();
        echo 'Test setup unexpectedly completed';
    } catch (Throwable $exception) {
        echo $exception::class.': '.$exception->getMessage();
    }
    PHP;

    $result = Process::path(base_path())->env([
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $unsafe ? $database : ':memory:',
        'DB_URL' => '',
    ])->run([PHP_BINARY, '-r', $script]);

    expect($result->successful())->toBeTrue()
        ->and($result->errorOutput())->toBe('')
        ->and($result->output())->toBe($expected)
        ->and(file_exists($database))->toBeFalse();
})->with([
    'isolated database reaches the refresh trait' => [false, 'RuntimeException: RefreshDatabase started'],
    'unsafe filename is rejected before the refresh trait' => [true, 'LogicException: Tests require the testing environment and SQLite :memory: without URL or read/write overrides.'],
]);
