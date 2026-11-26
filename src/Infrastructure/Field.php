<?php

namespace CipiGui\Infrastructure;

use Closure;

/**
 * One input of a form: an account's credentials, the options of an action,
 * the filters of a list. Options of a select may come from the provider:
 * a closure called with the API client and the scope when the form opens.
 */
final class Field
{
    public bool $required = false;

    /** Never shown again once saved (tokens, secret keys): blank keeps the stored value. */
    public bool $secret = false;

    /** A value, or a closure called with the row the action runs on. */
    public mixed $default = null;

    public ?string $help = null;

    public ?string $placeholder = null;

    /** @var array<array-key, string>|Closure|null */
    public array|Closure|null $options = null;

    /** @var list<string> */
    public array $rules = [];

    public function __construct(
        public string $name,
        public string $label,
        public string $type = 'text',
    ) {}

    public static function text(string $name, string $label): self
    {
        return new self($name, $label);
    }

    public static function password(string $name, string $label): self
    {
        return (new self($name, $label, 'password'))->secret();
    }

    public static function textarea(string $name, string $label): self
    {
        return new self($name, $label, 'textarea');
    }

    public static function number(string $name, string $label): self
    {
        return new self($name, $label, 'number');
    }

    /**
     * A value typed, or picked from suggestions (a DNS record's target: the
     * name of a server of the panel, or any address): the form offers the
     * options, the action reads what was typed.
     *
     * @param  array<array-key, string>|Closure  $options  value => label
     */
    public static function suggest(string $name, string $label, array|Closure $options): self
    {
        return (new self($name, $label, 'suggest'))->options($options);
    }

    public static function checkbox(string $name, string $label): self
    {
        return (new self($name, $label, 'checkbox'))->default(false);
    }

    /**
     * @param  array<array-key, string>|Closure  $options
     */
    public static function select(string $name, string $label, array|Closure $options): self
    {
        return (new self($name, $label, 'select'))->options($options);
    }

    /**
     * @param  array<array-key, string>|Closure  $options
     */
    public static function multiselect(string $name, string $label, array|Closure $options): self
    {
        return (new self($name, $label, 'multiselect'))->options($options)->default([]);
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    public function secret(bool $secret = true): self
    {
        $this->secret = $secret;

        return $this;
    }

    public function default(mixed $default): self
    {
        $this->default = $default;

        return $this;
    }

    public function help(string $help): self
    {
        $this->help = $help;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    /**
     * @param  array<array-key, string>|Closure  $options
     */
    public function options(array|Closure $options): self
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @param  list<string>  $rules
     */
    public function rules(array $rules): self
    {
        $this->rules = $rules;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function defaultFor(array $row): mixed
    {
        return $this->default instanceof Closure ? ($this->default)($row) : $this->default;
    }

    /**
     * Laravel validation rules for this field.
     *
     * @return list<mixed>
     */
    public function validation(bool $keepsStoredSecret = false): array
    {
        $rules = match ($this->type) {
            'number' => ['integer'],
            'checkbox' => ['boolean'],
            'multiselect' => ['array'],
            default => ['string', 'max:65535'],
        };

        $required = $this->required && ! ($keepsStoredSecret && $this->secret);

        return [$required ? 'required' : 'nullable', ...$rules, ...$this->rules];
    }

    /** The value as the API wants it: numbers as integers, checkboxes as booleans, blanks as null. */
    public function cast(mixed $value): mixed
    {
        return match ($this->type) {
            'number' => $value === null || $value === '' ? null : (int) $value,
            'checkbox' => (bool) $value,
            'multiselect' => array_values(array_filter((array) $value, fn ($item) => $item !== null && $item !== '')),
            default => is_string($value) ? (trim($value) === '' ? null : ($this->type === 'textarea' ? $value : trim($value))) : $value,
        };
    }
}
