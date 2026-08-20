<?php

namespace Vercy\Payments\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class SignatureVerificationException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['error' => 'Invalid webhook signature'], 400);
    }
}
