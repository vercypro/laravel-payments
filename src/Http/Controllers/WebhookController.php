<?php

namespace Vercy\Payments\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Vercy\Payments\Contracts\SupportsWebhooks;
use Vercy\Payments\Events\PaymentFailed;
use Vercy\Payments\Events\PaymentSucceeded;
use Vercy\Payments\Models\PaymentTransaction;
use Vercy\Payments\PaymentManager;

class WebhookController extends Controller
{
    public function handle(Request $request, string $gateway, PaymentManager $manager): JsonResponse
    {
        $driver = $manager->driver($gateway);

        if (! $driver instanceof SupportsWebhooks) {
            abort(404, "Gateway [{$gateway}] does not support webhooks.");
        }

        // parseWebhook() throws SignatureVerificationException on a bad
        // signature - let that propagate as a 4xx via the app's exception
        // handler rather than swallowing it here.
        $event = $driver->parseWebhook($request);

        $transaction = null;

        if (config('payment-gateway.persist_transactions', true)) {
            $transaction = PaymentTransaction::query()->where('reference', $event->reference)->first();

            if ($transaction) {
                match ($event->status) {
                    'success' => $transaction->markAsSuccessful($event->payload),
                    'failed' => $transaction->markAsFailed($event->payload),
                    'refunded' => $transaction->update(['status' => 'refunded', 'gateway_response' => $event->payload]),
                    default => $transaction->update(['gateway_response' => $event->payload]),
                };
            }
        }

        match ($event->status) {
            'success' => event(new PaymentSucceeded($event, $transaction)),
            'failed' => event(new PaymentFailed($event, $transaction)),
            default => null,
        };

        return response()->json(['ok' => true]);
    }
}
