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
