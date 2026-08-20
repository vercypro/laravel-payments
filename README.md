# Vercy Payments

A unified payment gateway package for Laravel. One consistent API across
**Paystack**, **Flutterwave**, **Stripe**, **PayPal**, **NowPayments**,
**Cryptomus**, and **BitPay** — card, bank, and crypto rails, all behind
the same contract.

## Why

Every provider has its own SDK, its own request shape, and its own webhook
signature scheme. This package normalizes all of that behind one
`PaymentGateway` interface, so switching providers — or picking one
automatically based on currency — doesn't mean rewriting your checkout flow.

## Installation

```bash
composer require vercy/payments

php artisan vendor:publish --tag=payment-gateway-config
php artisan migrate
```

Add your credentials to `.env`:

```env
PAYMENT_GATEWAY_DEFAULT=paystack

PAYSTACK_PUBLIC_KEY=
PAYSTACK_SECRET_KEY=

FLUTTERWAVE_PUBLIC_KEY=
FLUTTERWAVE_SECRET_KEY=
FLUTTERWAVE_WEBHOOK_SECRET=

STRIPE_PUBLIC_KEY=
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=

PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=
PAYPAL_WEBHOOK_ID=
PAYPAL_MODE=sandbox

NOWPAYMENTS_API_KEY=
NOWPAYMENTS_IPN_SECRET=

CRYPTOMUS_MERCHANT_ID=
CRYPTOMUS_API_KEY=

BITPAY_TOKEN=
BITPAY_ENV=test
```

## Basic usage

```php
use Vercy\Payments\Facades\Payment;
use Vercy\Payments\DTOs\PaymentRequest;

$response = Payment::driver('paystack')->initialize(new PaymentRequest(
    amount: 5000,
    currency: 'NGN',
    reference: 'ord_' . uniqid(),
    email: $user->email,
    callbackUrl: route('payments.callback'),
));

return redirect($response->checkoutUrl);
```

### Auto-select a gateway by currency

Configure `currency_preference` in `config/payment-gateway.php`, then:

```php
Payment::forCurrency('USDT')->initialize($request); // resolves to cryptomus
Payment::forCurrency('NGN')->initialize($request);  // resolves to paystack
```

### Verifying a transaction

```php
$result = Payment::driver('paystack')->verify($reference);

if ($result->success) {
    // fulfill the order
}
```

### Refunds (where supported)

Paystack, Stripe, Flutterwave, and PayPal support refunds. Crypto-only
gateways (NowPayments, Cryptomus, BitPay) generally don't — check before
calling:

```php
$driver = Payment::driver('stripe');

if ($driver instanceof \Vercy\Payments\Contracts\SupportsRefunds) {
    $driver->refund($reference);
}
```

## Webhooks

Every driver that supports webhooks is reachable at:

```
POST /payments/webhook/{gateway}
```

e.g. `https://yourapp.com/payments/webhook/paystack`,
`.../payments/webhook/stripe`, `.../payments/webhook/cryptomus`.

Point each provider's dashboard at its corresponding URL. The package
verifies the signature, updates the matching `PaymentTransaction` row (if
persistence is enabled), and fires `PaymentSucceeded` / `PaymentFailed`:

```php
// In a listener
public function handle(PaymentSucceeded $event): void
{
    $event->transaction; // PaymentTransaction|null
    $event->event->reference;
}
```

## Persistence

Transactions are recorded to the `payment_transactions` table
automatically when `payment-gateway.persist_transactions` is `true`
(default). Create your own row before redirecting the customer so the
webhook has something to match against:

```php
use Vercy\Payments\Models\PaymentTransaction;

PaymentTransaction::create([
    'reference' => $reference,
    'gateway' => 'paystack',
    'status' => 'pending',
    'amount' => 5000,
    'currency' => 'NGN',
    'email' => $user->email,
    'payable_id' => $order->id,
    'payable_type' => Order::class,
]);
```

## A note on Apple Pay / Google Pay

These are wallets, not independent processors — they produce a payment
token client-side (via `ApplePaySession` JS or the Google Pay API), and
that token is then charged through an actual processor such as Stripe.
This package doesn't ship a fake "ApplePayDriver" for that reason; wire
the wallet token straight into your chosen processor's driver (e.g.
Stripe's PaymentIntents confirmed with the wallet's payment method) on
the frontend/backend boundary that fits your checkout.

## Adding a new gateway

1. Implement `Contracts\PaymentGateway` (+ `SupportsWebhooks` /
   `SupportsRefunds` as applicable) in `src/Drivers/YourGatewayDriver.php`.
2. Register a `createYourgatewayDriver()` method in `PaymentManager`.
3. Add its config block to `config/payment-gateway.php`.

## Testing

Three layers, in order of how often you should run them:

**1. Unit tests** — mock HTTP, no network, run these on every commit:

```bash
composer install
vendor/bin/phpunit --testsuite=Unit
```

See `tests/Drivers/PaystackDriverTest.php` and `StripeDriverTest.php` for
the pattern: `Http::fake()` for `initialize()`/`verify()`, and manually
computed HMAC signatures for webhook parsing (both a valid signature and
a tampered payload, to prove rejection actually works and isn't a no-op).

**2. Feature tests** — hit the real `/payments/webhook/{gateway}` route
through the framework, using an in-memory sqlite database:

```bash
vendor/bin/phpunit --testsuite=Feature
```

`tests/Feature/WebhookControllerTest.php` posts a correctly-signed payload
and asserts the `PaymentTransaction` row updates and `PaymentSucceeded`
fires, then does the same with a bad signature and asserts nothing
changed and a 400 comes back (not a 500 — see `SignatureVerificationException::render()`).

**3. Sandbox smoke test** — before shipping, run this manually against
each gateway's test-mode credentials. This is the layer that catches
things unit tests can't: your webhook URL being unreachable, a provider
changing a field name, or your server clock being far enough off that
signature timestamps get rejected.

- [ ] `initialize()` returns a real checkout URL you can open in a browser
- [ ] Completing checkout with the provider's test card/wallet redirects
      back to your `callbackUrl`
- [ ] `verify()` against that real reference returns `success`
- [ ] The provider's dashboard shows the webhook was delivered with a
      `2xx` response (use a tunnel like `ngrok`/`expose` for local testing)
- [ ] Your `payment_transactions` row actually flips to `success` — not
      just the HTTP response
- [ ] Force a failure (declined test card, cancelled checkout) and confirm
      `PaymentFailed` fires, not silence
- [ ] Replay the same webhook payload twice (most providers retry) and
      confirm it doesn't double-fulfill the order — this package doesn't
      enforce idempotency for you beyond the unique `reference` column,
      so handle "already processed" in your own listener
- [ ] For crypto drivers specifically: test an underpayment/overpayment
      case if the provider's sandbox supports it (NowPayments and
      Cryptomus both do) — real customers will send the wrong amount

Test-mode credentials and test cards:
- Paystack: [test cards](https://paystack.com/docs/payments/test-payments/)
- Flutterwave: [test cards](https://developer.flutterwave.com/docs/integration-guides/testing-helpers)
- Stripe: [test cards](https://docs.stripe.com/testing)
- PayPal: sandbox buyer/seller accounts from the [Developer Dashboard](https://developer.paypal.com/dashboard/accounts)
- NowPayments / Cryptomus / BitPay: each has a sandbox/testnet mode toggle
  in their merchant dashboard — check current docs, these change

Don't skip the sandbox layer for the gateway you're launching with first.
Mocked tests prove your code does what you think it does; they can't
prove the provider's API still matches what you assumed.

## License

MIT
