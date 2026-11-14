<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected string $testModuleDb;

    protected function setUp(): void
    {
        parent::setUp();

        // Selalu gunakan database testing terisolasi agar TIDAK PERNAH menghapus / me-reset data di personal_app.sqlite
        $this->testModuleDb = database_path('modules/testing_personal_app.sqlite');
        if (! file_exists($this->testModuleDb)) {
            touch($this->testModuleDb);
        }

        config(['database.connections.personal_app.database' => $this->testModuleDb]);
        DB::purge('personal_app');
    }

    protected function tearDown(): void
    {
        DB::disconnect('personal_app');

        parent::tearDown();
    }
}
