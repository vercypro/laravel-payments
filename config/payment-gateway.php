<?php

return [

    /*
    |--------------------------------------------------------------------
    | Default Gateway
    |--------------------------------------------------------------------
    */
    'default' => env('PAYMENT_GATEWAY_DEFAULT', 'paystack'),

    /*
    |--------------------------------------------------------------------
    | Currency Preference (used by Payment::forCurrency())
    |--------------------------------------------------------------------
    | Order matters - first driver that supports the requested currency wins.
    */
    'currency_preference' => [
        'paystack', 'flutterwave', 'stripe', 'paypal', 'nowpayments', 'cryptomus', 'bitpay',
    ],

    /*
    |--------------------------------------------------------------------
    | Persistence
    |--------------------------------------------------------------------
    */
    'persist_transactions' => env('PAYMENT_GATEWAY_PERSIST', true),
    'transactions_table' => 'payment_transactions',

    /*
    |--------------------------------------------------------------------
    | Gateway Credentials
    |--------------------------------------------------------------------
    */
    'gateways' => [

        'paystack' => [
            'public_key' => env('PAYSTACK_PUBLIC_KEY'),
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'base_url' => 'https://api.paystack.co',
        ],

        'flutterwave' => [
            'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
            'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
            'webhook_secret' => env('FLUTTERWAVE_WEBHOOK_SECRET'),
            'base_url' => 'https://api.flutterwave.com/v3',
        ],

        'stripe' => [
            'public_key' => env('STRIPE_PUBLIC_KEY'),
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'base_url' => 'https://api.stripe.com/v1',
        ],

        'paypal' => [
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
            'mode' => env('PAYPAL_MODE', 'sandbox'), // sandbox|live
            'base_url' => env('PAYPAL_MODE', 'sandbox') === 'live'
                ? 'https://api-m.paypal.com'
                : 'https://api-m.sandbox.paypal.com',
        ],

        'nowpayments' => [
            'api_key' => env('NOWPAYMENTS_API_KEY'),
            'ipn_secret' => env('NOWPAYMENTS_IPN_SECRET'),
            'base_url' => 'https://api.nowpayments.io/v1',
        ],

        'cryptomus' => [
            'merchant_id' => env('CRYPTOMUS_MERCHANT_ID'),
            'api_key' => env('CRYPTOMUS_API_KEY'),
            'base_url' => 'https://api.cryptomus.com/v1',
        ],

        'bitpay' => [
            'token' => env('BITPAY_TOKEN'),
            'env' => env('BITPAY_ENV', 'test'), // test|prod
            'base_url' => env('BITPAY_ENV', 'test') === 'prod'
                ? 'https://bitpay.com'
                : 'https://test.bitpay.com',
        ],

    ],

];
