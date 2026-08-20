<?php

namespace Vercy\Payments\Contracts;

use Vercy\Payments\DTOs\PaymentRequest;
use Vercy\Payments\DTOs\PaymentResponse;
use Vercy\Payments\DTOs\VerificationResult;

interface PaymentGateway
{
    /**
     * Initialize a payment/checkout session and return a URL (or reference)
     * the customer should be redirected to / interact with.
     */
    public function initialize(PaymentRequest $request): PaymentResponse;

    /**
     * Verify a transaction's current status by reference.
     */
    public function verify(string $reference): VerificationResult;

    /**
     * Machine-readable slug, e.g. 'paystack', 'stripe'.
     */
    public function getName(): string;

    /**
     * ISO currency codes / asset tickers this driver supports.
     */
    public function supportedCurrencies(): array;
}
