<?php

namespace Vercy\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentTransaction extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'reference',
        'gateway_reference',
        'gateway',
        'status',
        'amount',
        'currency',
        'payable_id',
        'payable_type',
        'email',
        'metadata',
        'gateway_response',
        'paid_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'gateway_response' => 'array',
        'amount' => 'decimal:8',
        'paid_at' => 'datetime',
    ];

    public function getTable()
    {
        return config('payment-gateway.transactions_table', 'payment_transactions');
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function markAsSuccessful(array $gatewayResponse = []): void
    {
        $this->update([
            'status' => self::STATUS_SUCCESS,
            'paid_at' => now(),
            'gateway_response' => $gatewayResponse ?: $this->gateway_response,
        ]);
    }

    public function markAsFailed(array $gatewayResponse = []): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'gateway_response' => $gatewayResponse ?: $this->gateway_response,
        ]);
    }
}
