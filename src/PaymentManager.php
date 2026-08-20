<?php

namespace Vercy\Payments;

use Illuminate\Support\Manager;
use Vercy\Payments\Contracts\PaymentGateway;
use Vercy\Payments\Drivers\BitPayDriver;
use Vercy\Payments\Drivers\CryptomusDriver;
use Vercy\Payments\Drivers\FlutterwaveDriver;
use Vercy\Payments\Drivers\NowPaymentsDriver;
use Vercy\Payments\Drivers\PayPalDriver;
use Vercy\Payments\Drivers\PaystackDriver;
use Vercy\Payments\Drivers\StripeDriver;
use Vercy\Payments\Exceptions\InvalidGatewayException;

class PaymentManager extends Manager
{
    public function getDefaultDriver()
    {
        return $this->config->get('payment-gateway.default');
    }

    protected function gatewayConfig(string $driver): array
    {
        return $this->config->get("payment-gateway.gateways.{$driver}", []);
    }

    protected function createPaystackDriver(): PaystackDriver
    {
        return new PaystackDriver($this->gatewayConfig('paystack'));
    }

    protected function createFlutterwaveDriver(): FlutterwaveDriver
    {
        return new FlutterwaveDriver($this->gatewayConfig('flutterwave'));
    }

    protected function createStripeDriver(): StripeDriver
    {
        return new StripeDriver($this->gatewayConfig('stripe'));
    }

    protected function createPaypalDriver(): PayPalDriver
    {
        return new PayPalDriver($this->gatewayConfig('paypal'));
    }

    protected function createNowpaymentsDriver(): NowPaymentsDriver
    {
        return new NowPaymentsDriver($this->gatewayConfig('nowpayments'));
    }

    protected function createCryptomusDriver(): CryptomusDriver
    {
        return new CryptomusDriver($this->gatewayConfig('cryptomus'));
    }

    protected function createBitpayDriver(): BitPayDriver
    {
        return new BitPayDriver($this->gatewayConfig('bitpay'));
    }

    /**
     * Resolve the first configured driver (in currency_preference order)
     * that supports the given currency.
     */
    public function forCurrency(string $currency): PaymentGateway
    {
        $currency = strtoupper($currency);

        foreach ($this->config->get('payment-gateway.currency_preference', []) as $driverName) {
            $instance = $this->driver($driverName);

            if (in_array($currency, $instance->supportedCurrencies(), true)) {
                return $instance;
            }
        }

        throw new InvalidGatewayException("No configured gateway supports currency [{$currency}]");
    }
}
