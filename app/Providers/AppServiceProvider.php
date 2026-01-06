<?php

namespace App\Providers;

use App\Services\Payment\PaymentServiceInterface;
use App\Services\Payment\PaystackPaymentService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind PaymentServiceInterface to PaystackPaymentService
        $this->app->singleton(PaymentServiceInterface::class, function ($app) {
            return new PaystackPaymentService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
