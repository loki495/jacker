<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\DatabaseSafety;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        // Runs before Laravel initializes test traits, including RefreshDatabase.
        DatabaseSafety::ensureSafe($app);

        // Tests never read or write the real storage/app/agent (the host worker syncs the general resume there).
        $app->useStoragePath(sys_get_temp_dir().'/jacker-test-storage-'.getmypid());

        return $app;
    }
}
