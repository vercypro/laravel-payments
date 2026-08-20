<?php

namespace Vercy\Payments\Drivers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Vercy\Payments\Contracts\PaymentGateway;
use Vercy\Payments\Contracts\SupportsRefunds;
use Vercy\Payments\Contracts\SupportsWebhooks;
use Vercy\Payments\DTOs\PaymentRequest;
use Vercy\Payments\DTOs\PaymentResponse;
use Vercy\Payments\DTOs\VerificationResult;
use Vercy\Payments\DTOs\WebhookEvent;
use Vercy\Payments\Exceptions\PaymentInitializationException;
use Vercy\Payments\Exceptions\SignatureVerificationException;

/**
 * Stripe, via Checkout Sessions (redirect flow) for parity with the other
 * gateways in this package. Amounts are sent in the smallest currency unit.
 * Docs: https://docs.stripe.com/api/checkout/sessions
 */
class StripeDriver implements PaymentGateway, SupportsWebhooks, SupportsRefunds
{
    public function __construct(protected array $config)
    {
    }

    public function getName(): string
    {
        return 'stripe';
    }

    public function supportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY'];
    }

    protected function http()
    {
        return Http::baseUrl($this->config['base_url'])
            ->withToken($this->config['secret_key'])
            ->asForm() // Stripe's API is form-encoded, not JSON
            ->acceptJson()
            ->timeout(30);
    }

    public function initialize(PaymentRequest $request): PaymentResponse
    {
        $response = $this->http()->post('/checkout/sessions', [
            'mode' => 'payment',
            'success_url' => $request->callbackUrl.'?reference='.$request->reference,
            'cancel_url' => $request->callbackUrl.'?reference='.$request->reference.'&cancelled=1',
            'customer_email' => $request->email,
            'client_reference_id' => $request->reference,
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($request->currency),
                    'product_data' => ['name' => $request->description ?? 'Payment '.$request->reference],
                    'unit_amount' => (int) round($request->amount * 100),
                ],
                'quantity' => 1,
            ]],
            'metadata' => $request->metadata,
        ]);

        $body = $response->json();

        if (! $response->successful()) {
            throw new PaymentInitializationException($body['error']['message'] ?? 'Stripe initialization failed');
        }

        return new PaymentResponse(
            success: true,
            reference: $request->reference,
            checkoutUrl: $body['url'] ?? null,
            gatewayReference: $body['id'] ?? null,
            raw: $body,
        );
    }

    public function verify(string $reference): VerificationResult
    {
        // reference is the client_reference_id we set; look the session up
        // via its Stripe session id if you stored gatewayReference, otherwise
        // list sessions filtered by client_reference_id server-side in your app.
        $response = $this->http()->get("/checkout/sessions/{$reference}");
        $data = $response->json();
        $status = $data['payment_status'] ?? 'unpaid';

        return new VerificationResult(
            success: $status === 'paid',
            status: $this->normalizeStatus($status),
            reference: $data['client_reference_id'] ?? $reference,
            amount: ($data['amount_total'] ?? 0) / 100,
            currency: strtoupper($data['currency'] ?? 'USD'),
            raw: $data,
        );
    }

    public function refund(string $reference, ?float $amount = null): VerificationResult
    {
        // reference should be the PaymentIntent id here
        $payload = ['payment_intent' => $reference];
        if ($amount !== null) {
            $payload['amount'] = (int) round($amount * 100);
        }

        $response = $this->http()->post('/refunds', $payload);
        $data = $response->json();

        return new VerificationResult(
            success: $response->successful() && ($data['status'] ?? '') === 'succeeded',
            status: $this->normalizeStatus($data['status'] ?? 'failed'),
            reference: $reference,
            amount: ($data['amount'] ?? 0) / 100,
            currency: strtoupper($data['currency'] ?? 'USD'),
            raw: $data,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $signatureHeader = (string) $request->header('stripe-signature');
        $secret = (string) ($this->config['webhook_secret'] ?? '');

        if ($signatureHeader === '' || $secret === '') {
            return false;
        }

        // Format: t=timestamp,v1=signature
        $parts = [];
        foreach (explode(',', $signatureHeader) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $parts[$key] = $value;
        }

        if (empty($parts['t']) || empty($parts['v1'])) {
            return false;
        }

        $signedPayload = $parts['t'].'.'.$request->getContent();
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expected, $parts['v1']);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        if (! $this->verifyWebhookSignature($request)) {
            throw new SignatureVerificationException('Invalid Stripe webhook signature');
        }

        $payload = $request->json()->all();
        $object = $payload['data']['object'] ?? [];

        return new WebhookEvent(
            type: $payload['type'] ?? 'unknown',
            reference: $object['client_reference_id'] ?? $object['id'] ?? '',
            status: $this->normalizeStatus($object['payment_status'] ?? $object['status'] ?? 'unknown'),
            payload: $payload,
        );
    }

    protected function normalizeStatus(string $status): string
    {
        return match ($status) {
            'paid', 'succeeded', 'complete' => 'success',
            'unpaid', 'failed', 'canceled' => 'failed',
            'refunded' => 'refunded',
            default => 'pending',
        };
    }
}
