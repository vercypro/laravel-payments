<?php

namespace Vercy\Payments\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Vercy\Payments\Events\PaymentFailed;
use Vercy\Payments\Events\PaymentSucceeded;
use Vercy\Payments\Models\PaymentTransaction;
use Vercy\Payments\Tests\TestCase;

class WebhookControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadLaravelMigrations();
        $this->artisan('migrate', ['--force' => true]);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    public function test_a_valid_paystack_webhook_marks_the_transaction_successful_and_fires_event(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $transaction = PaymentTransaction::create([
            'reference' => 'ord_100',
            'gateway' => 'paystack',
            'status' => 'pending',
            'amount' => 5000,
            'currency' => 'NGN',
            'email' => 'buyer@example.com',
        ]);

        $secretKey = config('payment-gateway.gateways.paystack.secret_key');
        $payload = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => 'ord_100', 'status' => 'success'],
        ]);
        $signature = hash_hmac('sha512', $payload, $secretKey);

        $response = $this->call(
            'POST',
            '/payments/webhook/paystack',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-PAYSTACK-SIGNATURE' => $signature],
            $payload,
        );

        $response->assertOk();

        $this->assertSame('success', $transaction->fresh()->status);
        $this->assertNotNull($transaction->fresh()->paid_at);

        Event::assertDispatched(PaymentSucceeded::class, function ($event) {
            return $event->event->reference === 'ord_100';
        });
    }

    public function test_a_webhook_with_a_bad_signature_is_rejected_and_leaves_transaction_untouched(): void
    {
        Event::fake();

        $transaction = PaymentTransaction::create([
            'reference' => 'ord_101',
            'gateway' => 'paystack',
            'status' => 'pending',
            'amount' => 5000,
            'currency' => 'NGN',
        ]);

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => 'ord_101', 'status' => 'success'],
        ]);

        $response = $this->call(
            'POST',
            '/payments/webhook/paystack',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X-PAYSTACK-SIGNATURE' => 'not-a-real-signature'],
            $payload,
        );

        $response->assertStatus(400); // SignatureVerificationException::render() returns 400

        $this->assertSame('pending', $transaction->fresh()->status);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }
}
