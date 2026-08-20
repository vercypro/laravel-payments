<?php

namespace Vercy\Payments\Tests;

use Vercy\Payments\Drivers\PaystackDriver;
use Vercy\Payments\Facades\Payment;

class PaymentManagerTest extends TestCase
{
    public function test_it_resolves_the_default_driver(): void
    {
        $this->assertInstanceOf(PaystackDriver::class, Payment::driver());
    }

    public function test_it_resolves_a_named_driver(): void
    {
        $this->assertSame('paystack', Payment::driver('paystack')->getName());
    }

    public function test_it_resolves_by_currency_preference(): void
    {
        $this->assertSame('paystack', Payment::forCurrency('NGN')->getName());
    }
}
