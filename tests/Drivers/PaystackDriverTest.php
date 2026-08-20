<?php

namespace Vercy\Payments\Tests\Drivers;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Vercy\Payments\DTOs\PaymentRequest;
use Vercy\Payments\Drivers\PaystackDriver;
use Vercy\Payments\Exceptions\PaymentInitializationException;
use Vercy\Payments\Exceptions\SignatureVerificationException;
use Vercy\Payments\Tests\TestCase;

class PaystackDriverTest extends TestCase
{
    protected PaystackDriver $driver;
    protected string $secretKey = 'sk_test_dummy';

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new PaystackDriver([
            'secret_key' => $this->secretKey,
            'base_url' => 'https://api.paystack.co',
        ]);
    }

    public function test_initialize_returns_checkout_url_on_success(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/abc123',
                    'reference' => 'ord_1',
                ],
            ], 200),
        ]);

        $response = $this->driver->initialize(new PaymentRequest(
            amount: 5000,
            currency: 'NGN',
            reference: 'ord_1',
            email: 'test@example.com',
            callbackUrl: 'https://app.test/callback',
        ));

        $this->assertTrue($response->success);
        $this->assertSame('https://checkout.paystack.com/abc123', $response->checkoutUrl);

        // Confirm the amount was converted to kobo (smallest unit)
        Http::assertSent(function (ClientRequest $request) {
            return $request['amount'] === 500000 && $request['email'] === 'test@example.com';
        });
    }

    public function test_initialize_throws_on_gateway_failure(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => false,
                'message' => 'Invalid email',
            ], 400),
        ]);

        $this->expectException(PaymentInitializationException::class);

        $this->driver->initialize(new PaymentRequest(
            amount: 5000,
            currency: 'NGN',
            reference: 'ord_2',
            email: 'not-an-email',
            callbackUrl: 'https://app.test/callback',
        ));
    }

    public function test_verify_reports_success_for_successful_transaction(): void
    {
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'data' => [
                    'status' => 'success',
                    'amount' => 500000,
                    'currency' => 'NGN',
                ],
            ], 200),
        ]);

        $result = $this->driver->verify('ord_1');

        $this->assertTrue($result->success);
        $this->assertSame('success', $result->status);
        $this->assertSame(5000.0, $result->amount);
    }

    public function test_verify_reports_failure_for_abandoned_transaction(): void
    {
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'data' => ['status' => 'abandoned', 'amount' => 500000, 'currency' => 'NGN'],
            ], 200),
        ]);

        $result = $this->driver->verify('ord_3');

        $this->assertFalse($result->success);
        $this->assertSame('failed', $result->status);
    }

    public function test_webhook_signature_accepts_a_correctly_signed_payload(): void
    {
        $payload = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => 'ord_1', 'status' => 'success'],
        ]);

        $signature = hash_hmac('sha512', $payload, $this->secretKey);

        $request = Request::create('/payments/webhook/paystack', 'POST', [], [], [], [
            'HTTP_X-PAYSTACK-SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $this->assertTrue($this->driver->verifyWebhookSignature($request));

        $event = $this->driver->parseWebhook($request);
        $this->assertSame('success', $event->status);
        $this->assertSame('ord_1', $event->reference);
    }

    public function test_webhook_signature_rejects_a_tampered_payload(): void
    {
        $payload = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'ord_1']]);
        $signature = hash_hmac('sha512', $payload, $this->secretKey);

        // Attacker changes the body after the signature was computed
        $tampered = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'ord_999']]);

        $request = Request::create('/payments/webhook/paystack', 'POST', [], [], [], [
            'HTTP_X-PAYSTACK-SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $tampered);

        $this->assertFalse($this->driver->verifyWebhookSignature($request));

        $this->expectException(SignatureVerificationException::class);
        $this->driver->parseWebhook($request);
    }
}
