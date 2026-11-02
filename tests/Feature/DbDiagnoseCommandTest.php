<?php

namespace Tests\Feature;

use Tests\TestCase;

class DbDiagnoseCommandTest extends TestCase
{
    public function test_reports_unreachable_sql_server_quickly(): void
    {
        config([
            'database.default' => 'sqlsrv',
            'database.connections.sqlsrv.host' => '127.0.0.1',
            'database.connections.sqlsrv.port' => 1,
        ]);

        $started = microtime(true);

        $this->artisan('db:diagnose')
            ->expectsOutputToContain('TCP connect to 127.0.0.1:1')
            ->expectsOutputToContain('FAILED')
            ->assertExitCode(1);

        $this->assertLessThan(10, microtime(true) - $started);
    }
}
