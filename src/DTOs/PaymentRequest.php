<?php

namespace Vercy\Payments\DTOs;

final class PaymentRequest
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $reference,
        public readonly string $email,
        public readonly string $callbackUrl,
        public readonly array $metadata = [],
        public readonly ?string $customerName = null,
        public readonly ?string $description = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reference' => $this->reference,
            'email' => $this->email,
            'callback_url' => $this->callbackUrl,
            'metadata' => $this->metadata,
            'customer_name' => $this->customerName,
            'description' => $this->description,
        ];
    }
}
