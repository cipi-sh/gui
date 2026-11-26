<?php

namespace CipiGui\Infrastructure;

use Closure;

/**
 * One tab of a connector: a list of things at the provider (droplets, zones,
 * apps) with its columns, row actions and list actions, or an overview of
 * figures (kind "info"). A child list (the records of a zone) has no tab of
 * its own: a row action opens it with a scope.
 */
final class Section
{
    public string $icon = 'squares-2x2';

    public string $kind = 'table';

    public ?string $description = null;

    /** Tabs of a large connector come in groups (EC2, Lightsail…): one row of groups, one of tabs. */
    public ?string $group = null;

    public ?Closure $list = null;

    /** Dot path of the row's id, or a closure fn (array $row): string. */
    public string|Closure $id = 'id';

    /** @var list<Column> */
    public array $columns = [];

    /** @var list<Action> */
    public array $actions = [];

    /** @var list<Action> */
    public array $toolbar = [];

    /** @var list<Field> */
    public array $filters = [];

    /** Opened from a row of another list, which this key names. */
    public ?string $parent = null;

    /** The scope key whose value names the parent row in the breadcrumb. */
    public ?string $parentLabel = null;

    public function __construct(
        public string $key,
        public string $label,
    ) {}

    public static function make(string $key, string $label): self
    {
        return new self($key, $label);
    }

    /** An overview: fn (object $api, array $scope): array of figures and sections. */
    public static function info(string $key, string $label, Closure $info): self
    {
        $section = new self($key, $label);
        $section->kind = 'info';
        $section->list = $info;
        $section->icon = 'chart-bar';

        return $section;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function group(string $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** fn (object $api, array $scope): list<array> */
    public function list(Closure $list): self
    {
        $this->list = $list;

        return $this;
    }

    public function id(string|Closure $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @param  list<Column>  $columns
     */
    public function columns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * @param  list<Action>  $actions
     */
    public function actions(array $actions): self
    {
        $this->actions = $actions;

        return $this;
    }

    /**
     * @param  list<Action>  $actions
     */
    public function toolbar(array $actions): self
    {
        $this->toolbar = [...$this->toolbar, ...$actions];

        return $this;
    }

    /**
     * The usual list action: a form that creates one more.
     *
     * @param  list<Field>  $fields
     */
    public function create(string $label, array $fields, Closure $run): self
    {
        $this->toolbar[] = Action::make('create', $label)->icon('plus')->primary()->fields($fields)->run($run);

        return $this;
    }

    /**
     * @param  list<Field>  $filters
     */
    public function filters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    public function childOf(string $parent, string $label): self
    {
        $this->parent = $parent;
        $this->parentLabel = $label;

        return $this;
    }

    public function isChild(): bool
    {
        return $this->parent !== null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function rowId(array $row): string
    {
        $id = $this->id instanceof Closure ? ($this->id)($row) : data_get($row, $this->id);

        return is_scalar($id) ? (string) $id : '';
    }

    public function action(string $key): ?Action
    {
        foreach ($this->actions as $action) {
            if ($action->key === $key) {
                return $action;
            }
        }

        return null;
    }

    public function toolbarAction(string $key): ?Action
    {
        foreach ($this->toolbar as $action) {
            if ($action->key === $key) {
                return $action;
            }
        }

        return null;
    }
}
