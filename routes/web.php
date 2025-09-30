<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;

Route::get('/', function () {
    return view('welcome');
});

// Email verification route
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verifyWeb'])
    ->middleware(['signed'])
    ->name('verification.verify');

// Password reset route
Route::get('/password/reset/{token}', function ($token) {
    $frontendUrl = rtrim(env('FRONTEND_URL', 'https://app.thryft.net/'), '/');
    return redirect($frontendUrl . '/auth/reset-password?token=' . $token);
})->name('password.reset');
