<?php

namespace Vercy\Payments\Drivers;

use Illuminate\Http\Request;
use Vercy\Payments\Contracts\PaymentGateway;
use Vercy\Payments\Contracts\SupportsRefunds;
use Vercy\Payments\Contracts\SupportsWebhooks;
use Vercy\Payments\DTOs\PaymentRequest;
use Vercy\Payments\DTOs\PaymentResponse;
use Vercy\Payments\DTOs\VerificationResult;
use Vercy\Payments\DTOs\WebhookEvent;
use Vercy\Payments\Drivers\Concerns\BaseDriver;
use Vercy\Payments\Exceptions\PaymentInitializationException;
use Vercy\Payments\Exceptions\SignatureVerificationException;

/**
 * Paystack (NGN, GHS, ZAR, USD, KES). Amounts are sent to Paystack in the
 * smallest currency unit (kobo for NGN, cents for others).
 * Docs: https://paystack.com/docs/api/
 */
class PaystackDriver implements PaymentGateway, SupportsWebhooks, SupportsRefunds
{
    use BaseDriver;

    public function __construct(protected array $config)
    {
    }

    public function getName(): string
    {
        return 'paystack';
    }

    public function supportedCurrencies(): array
    {
        return ['NGN', 'GHS', 'ZAR', 'USD', 'KES'];
    }

    protected function http()
    {
        return \Illuminate\Support\Facades\Http::baseUrl($this->config['base_url'])
            ->withToken($this->config['secret_key'])
            ->acceptJson()
            ->timeout(30);
    }

    public function initialize(PaymentRequest $request): PaymentResponse
    {
        $response = $this->http()->post('/transaction/initialize', [
            'amount' => (int) round($request->amount * 100),
            'email' => $request->email,
            'currency' => $request->currency,
            'reference' => $request->reference,
            'callback_url' => $request->callbackUrl,
            'metadata' => $request->metadata,
        ]);

        $body = $response->json();

        if (! $response->successful() || empty($body['status'])) {
            throw new PaymentInitializationException($body['message'] ?? 'Paystack initialization failed');
        }

        return new PaymentResponse(
            success: true,
            reference: $request->reference,
            checkoutUrl: $body['data']['authorization_url'] ?? null,
            gatewayReference: $body['data']['reference'] ?? $request->reference,
            raw: $body,
        );
    }

    public function verify(string $reference): VerificationResult
    {
        $response = $this->http()->get("/transaction/verify/{$reference}");
        $data = $response->json('data', []);
        $status = $data['status'] ?? 'failed';

        return new VerificationResult(
            success: $status === 'success',
            status: $this->normalizeStatus($status),
            reference: $reference,
            amount: ($data['amount'] ?? 0) / 100,
            currency: $data['currency'] ?? 'NGN',
            raw: $data,
        );
    }

    public function refund(string $reference, ?float $amount = null): VerificationResult
    {
        $payload = ['transaction' => $reference];
        if ($amount !== null) {
            $payload['amount'] = (int) round($amount * 100);
        }

        $response = $this->http()->post('/refund', $payload);
        $data = $response->json('data', []);

        return new VerificationResult(
            success: $response->successful(),
            status: $this->normalizeStatus($data['status'] ?? 'failed'),
            reference: $reference,
            amount: ($data['amount'] ?? 0) / 100,
            currency: $data['currency'] ?? 'NGN',
            raw: $data,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $signature = $request->header('x-paystack-signature');
        $computed = hash_hmac('sha512', $request->getContent(), $this->config['secret_key']);

        return is_string($signature) && hash_equals($computed, $signature);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        if (! $this->verifyWebhookSignature($request)) {
            throw new SignatureVerificationException('Invalid Paystack webhook signature');
        }

        $payload = $request->json()->all();

        return new WebhookEvent(
            type: $payload['event'] ?? 'unknown',
            reference: $payload['data']['reference'] ?? '',
            status: $this->normalizeStatus($payload['data']['status'] ?? 'unknown'),
            payload: $payload,
        );
    }

    protected function normalizeStatus(string $status): string
    {
        return match ($status) {
            'success' => 'success',
            'failed', 'abandoned' => 'failed',
            'reversed' => 'refunded',
            default => 'pending',
        };
    }
}
