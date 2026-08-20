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
 * Flutterwave (NGN, GHS, KES, UGX, ZAR, USD, and more - pan-African + global).
 * Docs: https://developer.flutterwave.com/reference
 */
class FlutterwaveDriver implements PaymentGateway, SupportsWebhooks, SupportsRefunds
{
    public function __construct(protected array $config)
    {
    }

    public function getName(): string
    {
        return 'flutterwave';
    }

    public function supportedCurrencies(): array
    {
        return ['NGN', 'GHS', 'KES', 'UGX', 'TZS', 'ZAR', 'USD', 'GBP', 'EUR'];
    }

    protected function http()
    {
        return Http::baseUrl($this->config['base_url'])
            ->withToken($this->config['secret_key'])
            ->acceptJson()
            ->timeout(30);
    }

    public function initialize(PaymentRequest $request): PaymentResponse
    {
        $response = $this->http()->post('/payments', [
            'tx_ref' => $request->reference,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'redirect_url' => $request->callbackUrl,
            'customer' => [
                'email' => $request->email,
                'name' => $request->customerName,
            ],
            'meta' => $request->metadata,
        ]);

        $body = $response->json();

        if (! $response->successful() || ($body['status'] ?? null) !== 'success') {
            throw new PaymentInitializationException($body['message'] ?? 'Flutterwave initialization failed');
        }

        return new PaymentResponse(
            success: true,
            reference: $request->reference,
            checkoutUrl: $body['data']['link'] ?? null,
            gatewayReference: $request->reference,
            raw: $body,
        );
    }

    public function verify(string $reference): VerificationResult
    {
        // Flutterwave verifies by transaction id; if you only have tx_ref,
        // resolve it first via GET /transactions/verify_by_reference?tx_ref=
        $response = $this->http()->get('/transactions/verify_by_reference', [
            'tx_ref' => $reference,
        ]);

        $data = $response->json('data', []);
        $status = $data['status'] ?? 'failed';

        return new VerificationResult(
            success: $status === 'successful',
            status: $this->normalizeStatus($status),
            reference: $reference,
            amount: (float) ($data['amount'] ?? 0),
            currency: $data['currency'] ?? 'NGN',
            raw: $data,
        );
    }

    public function refund(string $reference, ?float $amount = null): VerificationResult
    {
        // reference here should be the Flutterwave numeric transaction id
        $payload = array_filter(['amount' => $amount]);
        $response = $this->http()->post("/transactions/{$reference}/refund", $payload);
        $data = $response->json('data', []);

        return new VerificationResult(
            success: $response->successful(),
            status: $this->normalizeStatus($data['status'] ?? 'failed'),
            reference: $reference,
            amount: (float) ($data['amount_refunded'] ?? 0),
            currency: $data['currency'] ?? 'NGN',
            raw: $data,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        // Flutterwave sends the static "secret hash" you configured in your
        // dashboard back in the verif-hash header - plain comparison, not HMAC.
        $signature = $request->header('verif-hash');
        $expected = (string) ($this->config['webhook_secret'] ?? $this->config['secret_key'] ?? '');

        return is_string($signature) && $expected !== '' && hash_equals($expected, $signature);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        if (! $this->verifyWebhookSignature($request)) {
            throw new SignatureVerificationException('Invalid Flutterwave webhook signature');
        }

        $payload = $request->json()->all();
        $data = $payload['data'] ?? [];

        return new WebhookEvent(
            type: $payload['event'] ?? 'unknown',
            reference: $data['tx_ref'] ?? '',
            status: $this->normalizeStatus($data['status'] ?? 'unknown'),
            payload: $payload,
        );
    }

    protected function normalizeStatus(string $status): string
    {
        return match ($status) {
            'successful', 'success' => 'success',
            'failed' => 'failed',
            default => 'pending',
        };
    }
}
