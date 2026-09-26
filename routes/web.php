<?php

use App\Http\Controllers\AttendantController;
use App\Http\Controllers\MachineController;
use App\Http\Middleware\EnsureAttendantUnlocked;
use Illuminate\Support\Facades\Route;

$themes = implode('|', array_map('preg_quote', array_keys(config('themes'))));

Route::get('/', [MachineController::class, 'show'])->name('machine.show');
Route::get('/{theme}', [MachineController::class, 'show'])->where('theme', $themes)->name('machine.theme');

Route::prefix('{theme}')->where(['theme' => $themes])->name('machine.')->group(function () {
    Route::get('/state', [MachineController::class, 'state'])->name('state');
    Route::get('/config', [MachineController::class, 'config'])->name('config');
    Route::post('/play', [MachineController::class, 'play'])->name('play')->block(lockSeconds: 10, waitSeconds: 10);
    Route::post('/denomination', [MachineController::class, 'changeDenomination'])->name('denomination')->block(lockSeconds: 10, waitSeconds: 10);
    Route::post('/reset', [MachineController::class, 'reset'])->name('reset')->block(lockSeconds: 10, waitSeconds: 10);
    Route::get('/par-sheet', [AttendantController::class, 'parSheet'])->name('par-sheet');
    Route::get('/par-sheet/live', [AttendantController::class, 'parLive'])->name('par-live');
});

Route::prefix('attendant')->name('attendant.')->group(function () {
    Route::get('/panel', [AttendantController::class, 'panel'])->name('panel');
    Route::post('/unlock', [AttendantController::class, 'unlock'])->name('unlock')->middleware('throttle:10,1');
    Route::post('/lock', [AttendantController::class, 'lock'])->name('lock');

    Route::middleware(EnsureAttendantUnlocked::class)->group(function () {
        Route::put('/settings', [AttendantController::class, 'updateSettings'])->name('settings');
        Route::post('/progressives/{tier}/reset', [AttendantController::class, 'resetProgressive'])->name('progressives.reset');
        Route::post('/meters/clear', [AttendantController::class, 'clearMeters'])->name('meters.clear');
        Route::post('/simulate', [AttendantController::class, 'simulate'])->name('simulate');
    });
});
