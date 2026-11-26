<?php

namespace CipiGui\Infrastructure;

/**
 * What an action leaves for the page: a toast, something to read (a new
 * password, a key, a log, a TXT record to publish) and, for the work a
 * provider does in the background, a token to ask about it again.
 */
final class Outcome
{
    public function __construct(
        public ?string $message = null,
        public mixed $data = null,
        public ?string $title = null,
        public ?string $poll = null,
    ) {}

    public static function done(?string $message = null): self
    {
        return new self($message ?? __('Done.'));
    }

    public static function show(string $title, mixed $data, ?string $message = null): self
    {
        return new self($message, $data, $title);
    }

    /** Still running at the provider: the page offers to check again with $token. */
    public static function pending(string $title, string $token, mixed $data = null): self
    {
        return new self(null, $data, $title, $token);
    }

    /** What an action closure returned, as an outcome. */
    public static function from(mixed $value): self
    {
        return match (true) {
            $value instanceof self => $value,
            $value === null => self::done(),
            is_string($value) => self::done($value),
            default => self::show(__('Result'), $value),
        };
    }
}
