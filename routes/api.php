<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApplicationController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Health check endpoint (no authentication required)
Route::get('/health', [ApplicationController::class, 'health'])->name('health');

// Fraud detection API endpoints
Route::prefix('v1')->group(function () {
    // Submit fraud detection request
    Route::post('/applications', [ApplicationController::class, 'store'])
        ->name('applications.store');
    
    // Get fraud detection decision
    Route::get('/decision/{job_id}', [ApplicationController::class, 'decision'])
        ->name('applications.decision');
});

// Legacy route support (without version prefix)
Route::post('/applications', [ApplicationController::class, 'store'])
    ->name('applications.store.legacy');

Route::get('/decision/{job_id}', [ApplicationController::class, 'decision'])
    ->name('applications.decision.legacy');
