<?php

namespace Vercy\Payments\Drivers\Concerns;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

trait BaseDriver
{
    protected function http(): PendingRequest
    {
        return Http::baseUrl($this->config['base_url'] ?? '')
            ->acceptJson()
            ->timeout(30);
    }
}
