<?php

use Illuminate\Support\Facades\Route;
use Vercy\Payments\Http\Controllers\WebhookController;

Route::post('/payments/webhook/{gateway}', [WebhookController::class, 'handle'])
    ->name('payments.webhook')
    ->withoutMiddleware(['csrf']); // webhooks come from the gateway, not the browser
