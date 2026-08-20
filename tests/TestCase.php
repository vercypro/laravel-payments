<?php

namespace Vercy\Payments\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vercy\Payments\PaymentGatewayServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [PaymentGatewayServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('payment-gateway.default', 'paystack');
        $app['config']->set('payment-gateway.gateways.paystack.secret_key', 'sk_test_dummy');
        $app['config']->set('payment-gateway.gateways.paystack.base_url', 'https://api.paystack.co');

        $app['config']->set('database.default', 'testing');
    }
}
