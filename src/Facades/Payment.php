<?php

namespace Vercy\Payments\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Vercy\Payments\Contracts\PaymentGateway driver(string $driver = null)
 * @method static \Vercy\Payments\Contracts\PaymentGateway forCurrency(string $currency)
 *
 * @see \Vercy\Payments\PaymentManager
 */
class Payment extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'payment';
    }
}
