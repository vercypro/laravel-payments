<?php

namespace Vercy\Payments\DTOs;

final class PaymentResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $reference,
        public readonly ?string $checkoutUrl = null,
        public readonly ?string $gatewayReference = null,
        public readonly array $raw = [],
    ) {
    }
}
