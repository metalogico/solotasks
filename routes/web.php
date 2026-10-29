<?php

use App\Http\Controllers\BoardController;
use App\Http\Controllers\MilestoneController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app')->name('home');

Route::prefix('api/v1')->name('api.')->group(function (): void {
    Route::get('session', [SessionController::class, 'show'])->name('session.show');
    Route::post('session', [SessionController::class, 'store'])->name('session.store');
    Route::delete('session', [SessionController::class, 'destroy'])->name('session.destroy');

    Route::middleware('personal')->group(function (): void {
        Route::get('board', BoardController::class)->name('board');
        Route::apiResource('projects', ProjectController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('milestones', MilestoneController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('tasks', TaskController::class)->only(['store', 'update', 'destroy']);
    });
});
