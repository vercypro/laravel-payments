<?php

namespace Vercy\Payments;

use Illuminate\Support\ServiceProvider;

class PaymentGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/payment-gateway.php', 'payment-gateway');

        $this->app->singleton('payment', fn ($app) => new PaymentManager($app));

        $this->app->alias('payment', PaymentManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/payment-gateway.php' => config_path('payment-gateway.php'),
            ], 'payment-gateway-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'payment-gateway-migrations');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
    }
}
