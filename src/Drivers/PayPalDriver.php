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
 * PayPal Orders API v2. OAuth2 client-credentials token is fetched and
 * cached per-request (swap the cache() call for Laravel's Cache facade
 * with a TTL just under the token's expires_in if you want it persisted).
 * Docs: https://developer.paypal.com/docs/api/orders/v2/
 */
class PayPalDriver implements PaymentGateway, SupportsWebhooks, SupportsRefunds
{
    protected ?string $accessToken = null;

    public function __construct(protected array $config)
    {
    }

    public function getName(): string
    {
        return 'paypal';
    }

    public function supportedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'CAD', 'AUD'];
    }

    protected function token(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $response = Http::asForm()
            ->withBasicAuth($this->config['client_id'], $this->config['client_secret'])
            ->post($this->config['base_url'].'/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        return $this->accessToken = $response->json('access_token');
    }

    protected function http()
    {
        return Http::baseUrl($this->config['base_url'])
            ->withToken($this->token())
            ->acceptJson()
            ->timeout(30);
    }

    public function initialize(PaymentRequest $request): PaymentResponse
    {
        $response = $this->http()->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $request->reference,
                'amount' => [
                    'currency_code' => $request->currency,
                    'value' => number_format($request->amount, 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'return_url' => $request->callbackUrl.'?reference='.$request->reference,
                'cancel_url' => $request->callbackUrl.'?reference='.$request->reference.'&cancelled=1',
            ],
        ]);

        $body = $response->json();

        if (! $response->successful()) {
            throw new PaymentInitializationException($body['message'] ?? 'PayPal initialization failed');
        }

        $approveUrl = collect($body['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;

        return new PaymentResponse(
            success: true,
            reference: $request->reference,
            checkoutUrl: $approveUrl,
            gatewayReference: $body['id'] ?? null,
            raw: $body,
        );
    }

    public function verify(string $reference): VerificationResult
    {
        // reference here is the PayPal Order ID (gatewayReference from initialize)
        $response = $this->http()->get("/v2/checkout/orders/{$reference}");
        $data = $response->json();
        $status = $data['status'] ?? 'CREATED'; // CREATED|APPROVED|COMPLETED|VOIDED

        $unit = $data['purchase_units'][0]['amount'] ?? ['value' => 0, 'currency_code' => 'USD'];

        return new VerificationResult(
            success: $status === 'COMPLETED',
            status: $this->normalizeStatus($status),
            reference: $data['purchase_units'][0]['reference_id'] ?? $reference,
            amount: (float) $unit['value'],
            currency: $unit['currency_code'],
            raw: $data,
        );
    }

    /**
     * Captures an approved order. Call this from your return_url handler
     * before treating the payment as settled - PayPal orders aren't
     * captured automatically.
     */
    public function capture(string $orderId): VerificationResult
    {
        $response = $this->http()->post("/v2/checkout/orders/{$orderId}/capture");
        $data = $response->json();
        $status = $data['status'] ?? 'FAILED';
        $unit = $data['purchase_units'][0]['payments']['captures'][0]['amount'] ?? ['value' => 0, 'currency_code' => 'USD'];

        return new VerificationResult(
            success: $status === 'COMPLETED',
            status: $this->normalizeStatus($status),
            reference: $data['purchase_units'][0]['reference_id'] ?? $orderId,
            amount: (float) $unit['value'],
            currency: $unit['currency_code'],
            raw: $data,
        );
    }

    public function refund(string $reference, ?float $amount = null): VerificationResult
    {
        // reference should be the capture ID here
        $payload = [];
        if ($amount !== null) {
            $payload['amount'] = ['value' => number_format($amount, 2, '.', ''), 'currency_code' => 'USD'];
        }

        $response = $this->http()->post("/v2/payments/captures/{$reference}/refund", $payload);
        $data = $response->json();

        return new VerificationResult(
            success: $response->successful(),
            status: $this->normalizeStatus($data['status'] ?? 'FAILED'),
            reference: $reference,
            amount: (float) ($data['amount']['value'] ?? 0),
            currency: $data['amount']['currency_code'] ?? 'USD',
            raw: $data,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        // PayPal webhook verification requires calling their
        // /v1/notifications/verify-webhook-signature endpoint with the
        // transmission headers - simplified here to a single call.
        $response = $this->http()->post('/v1/notifications/verify-webhook-signature', [
            'transmission_id' => $request->header('paypal-transmission-id'),
            'transmission_time' => $request->header('paypal-transmission-time'),
            'cert_url' => $request->header('paypal-cert-url'),
            'auth_algo' => $request->header('paypal-auth-algo'),
            'transmission_sig' => $request->header('paypal-transmission-sig'),
            'webhook_id' => $this->config['webhook_id'] ?? null,
            'webhook_event' => $request->json()->all(),
        ]);

        return $response->json('verification_status') === 'SUCCESS';
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        if (! $this->verifyWebhookSignature($request)) {
            throw new SignatureVerificationException('Invalid PayPal webhook signature');
        }

        $payload = $request->json()->all();
        $resource = $payload['resource'] ?? [];

        return new WebhookEvent(
            type: $payload['event_type'] ?? 'unknown',
            reference: $resource['purchase_units'][0]['reference_id'] ?? $resource['id'] ?? '',
            status: $this->normalizeStatus($resource['status'] ?? 'unknown'),
            payload: $payload,
        );
    }

    protected function normalizeStatus(string $status): string
    {
        return match ($status) {
            'COMPLETED' => 'success',
            'VOIDED', 'FAILED', 'DECLINED' => 'failed',
            'REFUNDED' => 'refunded',
            default => 'pending',
        };
    }
}
