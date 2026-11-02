<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('db:diagnose', function () {
    $name = config('database.default');
    $config = config("database.connections.{$name}");
    $host = $config['host'] ?? '';
    $port = (int) ($config['port'] ?? 1433);
    $failed = false;

    $this->info("1. Configuration ({$name})");
    $this->line('   Config cached: '.(app()->configurationIsCached()
        ? 'yes - run "php artisan config:clear" after editing .env'
        : 'no'));
    foreach (['host', 'port', 'database', 'username', 'encrypt', 'trust_server_certificate', 'login_timeout'] as $key) {
        $this->line("   {$key}: ".var_export($config[$key] ?? null, true));
    }

    $this->info('2. PHP drivers');
    $drivers = PDO::getAvailableDrivers();
    $this->line('   PDO drivers: '.(implode(', ', $drivers) ?: '(none)'));
    $this->line('   OPENSSL_CONF: '.(getenv('OPENSSL_CONF') ?: '(not set - Apache may set it, the CLI does not)'));
    if ($name === 'sqlsrv' && ! array_intersect(['sqlsrv', 'dblib'], $drivers)) {
        $this->error('   Neither pdo_sqlsrv nor pdo_dblib is loaded. See DEPLOYMENT.md section 2.');
        $failed = true;
    }

    $this->info("3. TCP connect to {$host}:{$port}");
    $started = microtime(true);
    $socket = @fsockopen($host, $port, $errno, $errstr, 5);
    $elapsed = round(microtime(true) - $started, 2);
    if (! $socket) {
        $this->error("   FAILED after {$elapsed}s: {$errstr} ({$errno})");
        $this->line('   The network or a firewall is blocking this server. Ask for this server\'s IP to be allowed on port '.$port.'.');

        return 1;
    }
    fclose($socket);
    $this->line("   OK ({$elapsed}s)");

    if ($failed) {
        return 1;
    }

    $this->info('4. SQL Server login and SELECT @@VERSION');
    $started = microtime(true);
    try {
        $version = DB::connection($name)->scalar('SELECT @@VERSION');
        $this->line('   OK ('.round(microtime(true) - $started, 2).'s): '.strtok($version, "\n"));
    } catch (Throwable $e) {
        $this->error('   FAILED after '.round(microtime(true) - $started, 2).'s: '.$e->getMessage());
        $this->line('   "SSL Provider" / "TCP Provider: Error code 0x2746" / "Login timeout": see DEPLOYMENT.md section 3 (TLS).');

        return 1;
    }

    return 0;
})->purpose('Check step by step why the app cannot reach SQL Server');
