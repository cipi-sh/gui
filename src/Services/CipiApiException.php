<?php

namespace CipiGui\Services;

use Exception;

class CipiApiException extends Exception
{
    public function __construct(
        string $message,
        protected int $statusCode = 500,
        protected ?array $details = null,
        ?Exception $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getDetails(): ?array
    {
        return $this->details;
    }

    public static function fromResponse(int $status, ?array $body = null): self
    {
        $message = $body['error']
            ?? $body['message']
            ?? "API request failed with status {$status}";

        $details = null;
        if (isset($body['errors']) && is_array($body['errors'])) {
            $details = $body['errors'];
        }

        return new self($message, $status, $details);
    }
}
