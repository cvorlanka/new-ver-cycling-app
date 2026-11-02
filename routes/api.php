<?php

use App\Http\Controllers\POController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:60,1')->group(function () {
    // Deployment check: confirms PHP can reach SQL Server.
    Route::get('health/db', function () {
        return response()->json([
            'status' => 'ok',
            'version' => DB::scalar('SELECT @@VERSION'),
        ]);
    });
});
