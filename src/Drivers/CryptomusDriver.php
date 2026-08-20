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
 * Cryptomus - crypto invoices with a settlement currency of your choice.
 * Every request/response is signed: sign = md5(base64(json_body) + api_key).
 * Docs: https://doc.cryptomus.com/business
 */
class CryptomusDriver implements PaymentGateway, SupportsWebhooks
{
    public function __construct(protected array $config)
    {
    }

    public function getName(): string
    {
        return 'cryptomus';
    }

    public function supportedCurrencies(): array
    {
        return ['USDT', 'BTC', 'ETH', 'TRX', 'USD']; // USD as settlement currency
    }

    protected function sign(array $payload): string
    {
        $encoded = base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return md5($encoded.$this->config['api_key']);
    }

    protected function post(string $endpoint, array $payload)
    {
        return Http::baseUrl($this->config['base_url'])
            ->withHeaders([
                'merchant' => $this->config['merchant_id'],
                'sign' => $this->sign($payload),
            ])
            ->acceptJson()
            ->timeout(30)
            ->post($endpoint, $payload);
    }

    public function initialize(PaymentRequest $request): PaymentResponse
    {
        $payload = [
            'amount' => (string) $request->amount,
            'currency' => $request->currency,
            'order_id' => $request->reference,
            'url_callback' => $request->callbackUrl,
            'url_return' => $request->callbackUrl.'?reference='.$request->reference,
        ];

        $response = $this->post('/payment', $payload);
        $body = $response->json();
        $data = $body['result'] ?? [];

        if (! $response->successful() || empty($data['url'])) {
            throw new PaymentInitializationException($body['message'] ?? 'Cryptomus initialization failed');
        }

        return new PaymentResponse(
            success: true,
            reference: $request->reference,
            checkoutUrl: $data['url'],
            gatewayReference: $data['uuid'] ?? null,
            raw: $data,
        );
    }

    public function verify(string $reference): VerificationResult
    {
        $payload = ['order_id' => $reference];
        $response = $this->post('/payment/info', $payload);
        $data = $response->json('result', []);
        $status = $data['payment_status'] ?? 'fail';

        return new VerificationResult(
            success: in_array($status, ['paid', 'paid_over'], true),
            status: $this->normalizeStatus($status),
            reference: $reference,
            amount: (float) ($data['amount'] ?? 0),
            currency: $data['currency'] ?? 'USDT',
            raw: $data,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $payload = $request->json()->all();
        $signature = $payload['sign'] ?? null;

        if (! is_string($signature)) {
            return false;
        }

        unset($payload['sign']);

        return hash_equals($this->sign($payload), $signature);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        if (! $this->verifyWebhookSignature($request)) {
            throw new SignatureVerificationException('Invalid Cryptomus webhook signature');
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
            'paid', 'paid_over' => 'success',
            'fail', 'cancel', 'wrong_amount' => 'failed',
            'refund_paid' => 'refunded',
            default => 'pending', // process|confirm_check|check
        };
    }
}
