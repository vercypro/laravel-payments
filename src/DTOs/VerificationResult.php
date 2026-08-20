<?php

namespace Vercy\Payments\DTOs;

final class VerificationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status, // pending|success|failed|refunded|expired
        public readonly string $reference,
        public readonly float $amount,
        public readonly string $currency,
        public readonly array $raw = [],
    ) {
    }
}
