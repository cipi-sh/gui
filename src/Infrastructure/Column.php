<?php

namespace CipiGui\Infrastructure;

use Closure;
use Illuminate\Support\Carbon;

/**
 * A column of a list: a dot path in the row or a closure, and how to show it
 * (text, mono, badge, bool, date).
 */
final class Column
{
    public string $format = 'text';

    /** @var array<string, string> badge colour by value; '*' for any other */
    public array $colors = [];

    public ?Closure $using = null;

    /** Dot path of an address in the row, or fn (array $row): ?string: the cell opens it in a new tab. */
    public string|Closure|null $link = null;

    public function __construct(
        public string $name,
        public string $label,
    ) {}

    public static function make(string $name, string $label): self
    {
        return new self($name, $label);
    }

    public function using(Closure $using): self
    {
        $this->using = $using;

        return $this;
    }

    public function mono(): self
    {
        $this->format = 'mono';

        return $this;
    }

    /**
     * @param  array<string, string>  $colors
     */
    public function badge(array $colors = []): self
    {
        $this->format = 'badge';
        $this->colors = $colors;

        return $this;
    }

    public function link(string|Closure $link): self
    {
        $this->link = $link;

        return $this;
    }

    /**
     * Where the cell leads, when it is a web address.
     *
     * @param  array<string, mixed>  $row
     */
    public function href(array $row): ?string
    {
        $href = match (true) {
            $this->link instanceof Closure => ($this->link)($row),
            is_string($this->link) => data_get($row, $this->link),
            default => null,
        };

        return is_string($href) && preg_match('#^https?://#i', $href) ? $href : null;
    }

    public function bool(): self
    {
        $this->format = 'bool';

        return $this;
    }

    public function date(): self
    {
        $this->format = 'date';

        return $this;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function value(array $row): mixed
    {
        return $this->using ? ($this->using)($row) : data_get($row, $this->name);
    }

    /**
     * The value as text, for the cell and for the search box.
     *
     * @param  array<string, mixed>  $row
     */
    public function text(array $row): string
    {
        $value = $this->value($row);

        if ($this->format === 'date' && is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value)->diffForHumans();
            } catch (\Throwable) {
                return $value;
            }
        }

        return match (true) {
            is_bool($value) => $value ? __('yes') : __('no'),
            is_array($value) => implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : (string) json_encode($item), $value)),
            $value === null => '',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function color(array $row): string
    {
        $value = $this->value($row);
        $key = is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? strtolower((string) $value) : '');

        return $this->colors[$key] ?? $this->colors['*'] ?? 'zinc';
    }
}
