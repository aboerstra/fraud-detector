<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TestUIController;

Route::get('/', function () {
    return view('welcome');
});

// Testing UI Routes
Route::get('/test-ui', [TestUIController::class, 'index'])->name('test-ui');
Route::post('/test-ui/generate-data', [TestUIController::class, 'generateTestData'])->name('test-ui.generate-data');
Route::get('/test-ui/system-health', [TestUIController::class, 'systemHealth'])->name('test-ui.system-health');

// Enhanced LLM Adjudicator Testing Routes
Route::post('/test-ui/migration-test', [TestUIController::class, 'testMigration'])->name('test-ui.migration-test');
Route::post('/test-ui/canary-test', [TestUIController::class, 'runCanaryTest'])->name('test-ui.canary-test');
Route::post('/test-ui/reset-circuit-breaker', [TestUIController::class, 'resetCircuitBreaker'])->name('test-ui.reset-circuit-breaker');
