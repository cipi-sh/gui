<?php

namespace CipiGui\Infrastructure;

/**
 * An API client that also carries what every call of the account needs
 * besides its token: a Google Cloud project, an Azure subscription.
 */
class ScopedClient extends ApiClient
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $scope
     */
    public function __construct(string $baseUrl, array $headers = [], public readonly array $scope = [], int $timeout = 30)
    {
        parent::__construct($baseUrl, $headers, $timeout);
    }

    public function scope(string $key): string
    {
        return (string) ($this->scope[$key] ?? '');
    }
}
