<?php

namespace Vercy\Payments\Contracts;

use Vercy\Payments\DTOs\VerificationResult;

interface SupportsRefunds
{
    /**
     * Refund a transaction, fully or partially (amount in the gateway's
     * smallest/major unit as appropriate for that driver - see each
     * driver's docblock).
     */
    public function refund(string $reference, ?float $amount = null): VerificationResult;
}
