<?php

namespace CipiGui\Infrastructure;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;

/**
 * Contabo's API wants a fresh UUID in x-request-id on every call.
 */
class ContaboClient extends ApiClient
{
    protected function request(): PendingRequest
    {
        return parent::request()->withHeaders(['x-request-id' => (string) Str::uuid()]);
    }
}
