<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('payment-gateway.transactions_table', 'payment_transactions'), function (Blueprint $table) {
            $table->id();

            // Internal reference we generate, and the gateway's own reference/id if different.
            $table->string('reference')->unique();
            $table->string('gateway_reference')->nullable()->index();

            $table->string('gateway'); // paystack|flutterwave|stripe|paypal|nowpayments|cryptomus|bitpay
            $table->string('status')->default('pending'); // pending|success|failed|refunded|expired

            $table->decimal('amount', 20, 8);
            $table->string('currency', 10);

            // Optional link to whatever the payment is for (order, subscription, invoice...)
            $table->nullableMorphs('payable');

            $table->string('email')->nullable();
            $table->json('metadata')->nullable();
            $table->json('gateway_response')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['gateway', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('payment-gateway.transactions_table', 'payment_transactions'));
    }
};
