<?php

namespace Vercy\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Vercy\Payments\DTOs\WebhookEvent;
use Vercy\Payments\Models\PaymentTransaction;

class PaymentFailed
{
    use Dispatchable;

    public function __construct(
        public readonly WebhookEvent $event,
        public readonly ?PaymentTransaction $transaction = null,
    ) {
    }
}
