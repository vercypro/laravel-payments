<?php

namespace Vercy\Payments\Tests\Drivers;

use Illuminate\Http\Request;
use Vercy\Payments\Drivers\StripeDriver;
use Vercy\Payments\Exceptions\SignatureVerificationException;
use Vercy\Payments\Tests\TestCase;

class StripeDriverTest extends TestCase
{
    protected StripeDriver $driver;
    protected string $webhookSecret = 'whsec_test_dummy';

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = new StripeDriver([
            'secret_key' => 'sk_test_dummy',
            'webhook_secret' => $this->webhookSecret,
            'base_url' => 'https://api.stripe.com/v1',
        ]);
    }

    protected function buildSignedRequest(array $payload, ?int $timestamp = null): Request
    {
        $timestamp ??= time();
        $body = json_encode($payload);
        $signedPayload = $timestamp.'.'.$body;
        $signature = hash_hmac('sha256', $signedPayload, $this->webhookSecret);

        return Request::create('/payments/webhook/stripe', 'POST', [], [], [], [
            'HTTP_STRIPE-SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_accepts_a_correctly_signed_webhook(): void
    {
        $request = $this->buildSignedRequest([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'client_reference_id' => 'ord_1',
                'payment_status' => 'paid',
            ]],
        ]);

        $this->assertTrue($this->driver->verifyWebhookSignature($request));

        $event = $this->driver->parseWebhook($request);
        $this->assertSame('success', $event->status);
        $this->assertSame('ord_1', $event->reference);
    }

    public function test_rejects_a_webhook_with_wrong_secret(): void
    {
        $body = json_encode(['type' => 'checkout.session.completed']);
        $timestamp = time();
        $badSignature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_wrong_secret');

        $request = Request::create('/payments/webhook/stripe', 'POST', [], [], [], [
            'HTTP_STRIPE-SIGNATURE' => "t={$timestamp},v1={$badSignature}",
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $this->assertFalse($this->driver->verifyWebhookSignature($request));
        $this->expectException(SignatureVerificationException::class);
        $this->driver->parseWebhook($request);
    }

    public function test_rejects_a_request_with_missing_signature_header(): void
    {
        $request = Request::create('/payments/webhook/stripe', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['type' => 'checkout.session.completed']));

        $this->assertFalse($this->driver->verifyWebhookSignature($request));
    }
}
