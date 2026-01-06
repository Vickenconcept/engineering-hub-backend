<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentCallbackController;

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
