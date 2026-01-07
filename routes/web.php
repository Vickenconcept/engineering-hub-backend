<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentCallbackController;
use App\Http\Controllers\TestGoogleMeetController;

Route::get('/', function () {
    return view('welcome');
});

/**
 * Payment callback route
 * Paystack redirects here after payment
 * We verify the payment, update the database, then redirect to frontend
 */
Route::get('/payment/callback', [PaymentCallbackController::class, 'handle'])
    ->name('payment.callback');

/**
 * Test Google Meet integration
 * Access via: http://localhost:8000/test-google-meet
 */
Route::get('/test-google-meet', [TestGoogleMeetController::class, 'test'])
    ->name('test.google-meet');
