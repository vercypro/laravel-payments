<?php

namespace Vercy\Payments\Contracts;

use Illuminate\Http\Request;
use Vercy\Payments\DTOs\WebhookEvent;

interface SupportsWebhooks
{
    /**
     * Verify the authenticity of an incoming webhook request.
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Parse a verified webhook request into a normalized WebhookEvent.
     * Implementations should throw SignatureVerificationException if
     * the signature is invalid.
     */
    public function parseWebhook(Request $request): WebhookEvent;
}
