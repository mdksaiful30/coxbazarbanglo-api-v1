<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('app'))->name('login');
Route::get('/reset-password/{token}', fn () => view('app'))->name('password.reset');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1');
Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:6,1');
Route::post('/logout', [AuthController::class, 'logout'])->middleware(['auth', 'active']);
