<?php

namespace Vercy\Payments\DTOs;

final class WebhookEvent
{
    public function __construct(
        public readonly string $type,
        public readonly string $reference,
        public readonly string $status, // normalized: pending|success|failed|refunded|expired
        public readonly array $payload,
    ) {
    }
}
