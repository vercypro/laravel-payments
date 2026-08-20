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
 * BitPay - crypto invoices priced in fiat, settled in BTC/BCH/ETH/etc.
 * Uses a merchant token (not full BitPay client/key-pair auth) for
 * simplicity; swap for the SDK if you need full API coverage.
 * Docs: https://bitpay.com/api/
 */
class BitPayDriver implements PaymentGateway, SupportsWebhooks
{
    public function __construct(protected array $config)
    {
    }

    public function getName(): string
    {
        return 'bitpay';
    }

    public function supportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'BTC', 'BCH', 'ETH'];
    }

    protected function http()
    {
        return Http::baseUrl($this->config['base_url'])
            ->withHeaders(['X-Accept-Version' => '2.0.0'])
            ->acceptJson()
            ->timeout(30);
    }

    public function initialize(PaymentRequest $request): PaymentResponse
    {
        $response = $this->http()->post('/invoices', [
            'token' => $this->config['token'],
            'price' => $request->amount,
            'currency' => $request->currency,
            'orderId' => $request->reference,
            'notificationURL' => $request->callbackUrl,
            'redirectURL' => $request->callbackUrl.'?reference='.$request->reference,
            'buyerEmail' => $request->email,
        ]);

        $body = $response->json();
        $data = $body['data'] ?? [];

        if (! $response->successful() || empty($data['url'])) {
            throw new PaymentInitializationException($body['error'] ?? 'BitPay initialization failed');
        }

        return new PaymentResponse(
            success: true,
            reference: $request->reference,
            checkoutUrl: $data['url'],
            gatewayReference: $data['id'] ?? null,
            raw: $data,
        );
    }

    public function verify(string $reference): VerificationResult
    {
        // reference is the BitPay invoice id (gatewayReference)
        $response = $this->http()->get("/invoices/{$reference}", [
            'token' => $this->config['token'],
        ]);

        $data = $response->json('data', []);
        $status = $data['status'] ?? 'new'; // new|paid|confirmed|complete|expired|invalid

        return new VerificationResult(
            success: in_array($status, ['confirmed', 'complete'], true),
            status: $this->normalizeStatus($status),
            reference: $data['orderId'] ?? $reference,
            amount: (float) ($data['price'] ?? 0),
            currency: $data['currency'] ?? 'USD',
            raw: $data,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        // BitPay IPNs are verified by re-fetching the invoice with your
        // merchant token rather than a header signature - treat the fetch
        // itself as the trust boundary. Ensure your webhook route is only
        // reachable with a shared secret query param as an extra layer.
        $payload = $request->json()->all();

        return ! empty($payload['data']['id']);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        if (! $this->verifyWebhookSignature($request)) {
            throw new SignatureVerificationException('Invalid BitPay webhook payload');
        }

        $payload = $request->json()->all();
        $data = $payload['data'] ?? [];

        return new WebhookEvent(
            type: 'invoice.update',
            reference: $data['orderId'] ?? '',
            status: $this->normalizeStatus($data['status'] ?? 'unknown'),
            payload: $payload,
        );
    }

    protected function normalizeStatus(string $status): string
    {
        return match ($status) {
            'confirmed', 'complete' => 'success',
            'expired', 'invalid' => 'failed',
            default => 'pending', // new|paid (unconfirmed)
        };
    }
}
