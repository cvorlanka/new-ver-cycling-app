<?php

use App\Http\Controllers\CVOWebsiteSupportController;
use Illuminate\Support\Facades\Route;

// Loaded from bootstrap/app.php under the "api" middleware group, without the /api prefix.
Route::middleware('throttle:60,1')->prefix('magento')->group(function () {
    Route::get('lab/{lab}', [CVOWebsiteSupportController::class, 'validateLab']);
    Route::post('add_lab', [CVOWebsiteSupportController::class, 'addCustomerLab']);
    Route::get('account/{account}', [CVOWebsiteSupportController::class, 'accountData']);
    Route::get('validate/{acc}/{inv}', [CVOWebsiteSupportController::class, 'validateAccount']);
});
