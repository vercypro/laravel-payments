<?php

namespace Vercy\Payments\Drivers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Vercy\Payments\Contracts\PaymentGateway;
use Vercy\Payments\Contracts\SupportsWebhooks;
use Vercy\Payments\DTOs\PaymentRequest;
use Vercy\Payments\DTOs\PaymentResponse;
use Vercy\Payments\DTOs\VerificationResult;
use Vercy\Payments\DTOs\WebhookEvent;
use Vercy\Payments\Exceptions\PaymentInitializationException;
use Vercy\Payments\Exceptions\SignatureVerificationException;

/**
 * NowPayments - crypto invoices settling in 300+ coins. No refund API for
 * end customers (refunds are handled manually / via support).
 * Docs: https://documenter.getpostman.com/view/7907941/S1a32n38
 */
class NowPaymentsDriver implements PaymentGateway, SupportsWebhooks
{
    public function __construct(protected array $config)
    {
    }

    public function getName(): string
    {
        return 'nowpayments';
    }

    public function supportedCurrencies(): array
    {
        return ['BTC', 'ETH', 'USDT', 'USDC', 'LTC', 'BNB', 'USD']; // USD as price_currency
    }

    protected function http()
    {
        return Http::baseUrl($this->config['base_url'])
            ->withHeaders(['x-api-key' => $this->config['api_key']])
            ->acceptJson()
            ->timeout(30);
    }

    public function initialize(PaymentRequest $request): PaymentResponse
    {
        $response = $this->http()->post('/invoice', [
            'price_amount' => $request->amount,
            'price_currency' => $request->currency,
            'order_id' => $request->reference,
            'order_description' => $request->description ?? 'Payment '.$request->reference,
            'ipn_callback_url' => $request->callbackUrl,
            'success_url' => $request->callbackUrl.'?reference='.$request->reference,
            'cancel_url' => $request->callbackUrl.'?reference='.$request->reference.'&cancelled=1',
        ]);

        $body = $response->json();

        if (! $response->successful()) {
            throw new PaymentInitializationException($body['message'] ?? 'NowPayments initialization failed');
        }

        return new PaymentResponse(
            success: true,
            reference: $request->reference,
            checkoutUrl: $body['invoice_url'] ?? null,
            gatewayReference: (string) ($body['id'] ?? ''),
            raw: $body,
        );
    }

    public function verify(string $reference): VerificationResult
    {
        // reference is the NowPayments payment/invoice id (gatewayReference)
        $response = $this->http()->get("/payment/{$reference}");
        $data = $response->json();
        $status = $data['payment_status'] ?? 'waiting';

        return new VerificationResult(
            success: in_array($status, ['finished', 'confirmed'], true),
            status: $this->normalizeStatus($status),
            reference: $data['order_id'] ?? $reference,
            amount: (float) ($data['price_amount'] ?? 0),
            currency: $data['price_currency'] ?? 'USD',
            raw: $data,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $signature = $request->header('x-nowpayments-sig');
        $secret = (string) ($this->config['ipn_secret'] ?? '');

        if (! is_string($signature) || $secret === '') {
            return false;
        }

        // NowPayments signs the JSON payload with keys sorted alphabetically.
        $payload = $request->json()->all();
        ksort($payload);
        $sorted = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $computed = hash_hmac('sha512', $sorted, $secret);

        return hash_equals($computed, $signature);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        if (! $this->verifyWebhookSignature($request)) {
            throw new SignatureVerificationException('Invalid NowPayments IPN signature');
        }

        $payload = $request->json()->all();

        return new WebhookEvent(
            type: 'payment.update',
            reference: $payload['order_id'] ?? '',
            status: $this->normalizeStatus($payload['payment_status'] ?? 'unknown'),
            payload: $payload,
        );
    }

    protected function normalizeStatus(string $status): string
    {
        return match ($status) {
            'finished', 'confirmed' => 'success',
            'failed', 'expired' => 'failed',
            'refunded' => 'refunded',
            default => 'pending', // waiting|confirming|sending|partially_paid
        };
    }
}
