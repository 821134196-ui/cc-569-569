<?php

namespace App\Providers;

use App\Services\Contracts\PaymentGateway;
use App\Services\Contracts\RoomStatusProvider;
use App\Services\Mock\MockPaymentGateway;
use App\Services\Mock\MockRoomStatusProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 支付与房态接口：本地模拟实现
        $this->app->bind(PaymentGateway::class, MockPaymentGateway::class);
        $this->app->bind(RoomStatusProvider::class, MockRoomStatusProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
