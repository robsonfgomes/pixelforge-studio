<?php

use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationEstimateController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');

    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:generation-requests')
        ->name('generations.store');

    Route::post('generations/estimate', GenerationEstimateController::class)
        ->middleware('throttle:generation-estimates')
        ->name('generations.estimate');
});

require __DIR__.'/settings.php';
