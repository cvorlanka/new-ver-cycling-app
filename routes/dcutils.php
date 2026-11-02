<?php

use App\Http\Controllers\DCUtilsController;
use App\Http\Controllers\PackingListController;
use Illuminate\Support\Facades\Route;

// Loaded from bootstrap/app.php under the "api" middleware group, without the /api prefix.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('case_label', [DCUtilsController::class, 'printLabel']);
    Route::post('confirm_label', [DCUtilsController::class, 'confirmLabel']);

    Route::prefix('dc')->group(function () {
        Route::get('order/{order_no}', [PackingListController::class, 'getOrder']);
        Route::get('order_lines/{order_no}', [PackingListController::class, 'getOrderLines']);
    });
});
