<?php

namespace CipiGui\Infrastructure;

use Closure;

/**
 * Something done on a row (reboot a server, purge a zone) or on the whole
 * list (create, export). It runs a closure with the API client, the row
 * (empty for a list action), the data of its form and the scope, or opens a
 * child list (the records of a zone) with a scope built from the row.
 */
final class Action
{
    public string $icon = 'bolt';

    public ?string $confirm = null;

    public bool $danger = false;

    public bool $primary = false;

    /** @var list<Field> */
    public array $fields = [];

    public ?Closure $run = null;

    public ?string $opens = null;

    public ?Closure $scope = null;

    public ?Closure $when = null;

    public function __construct(
        public string $key,
        public string $label,
    ) {}

    public static function make(string $key, string $label): self
    {
        return new self($key, $label);
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function confirm(string $confirm): self
    {
        $this->confirm = $confirm;

        return $this;
    }

    /** Destructive: red in the menu, always confirmed. */
    public function danger(?string $confirm = null): self
    {
        $this->danger = true;
        $this->confirm = $confirm ?? $this->confirm ?? __('Are you sure? This cannot be undone.');

        return $this;
    }

    public function primary(): self
    {
        $this->primary = true;

        return $this;
    }

    /**
     * @param  list<Field>  $fields
     */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    /** fn (object $api, array $row, array $data, array $scope): Outcome|string|array|null */
    public function run(Closure $run): self
    {
        $this->run = $run;

        return $this;
    }

    /** Open the child list $section with the scope fn (array $row): array. */
    public function opens(string $section, Closure $scope): self
    {
        $this->opens = $section;
        $this->scope = $scope;

        return $this;
    }

    /** Only for the rows where fn (array $row): bool says so. */
    public function when(Closure $when): self
    {
        $this->when = $when;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function appliesTo(array $row): bool
    {
        return $this->when === null || (bool) ($this->when)($row);
    }

    public function hasForm(): bool
    {
        return $this->fields !== [];
    }
}
