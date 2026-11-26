<?php

namespace CipiGui\Livewire;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\Connectors;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\Section;
use CipiGui\Models\CipiConnectorAccount;
use CipiGui\Services\Access;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The page of one infrastructure connector (Cloudflare, DigitalOcean, AWS…):
 * its accounts as tabs, a tab per list the connector describes, the actions
 * on each row and on the list. What is read from a provider is kept for a
 * couple of minutes; anything done from here clears it. Admins only; the
 * accounts are added in Settings → Connectors. A connector describes, this
 * page renders and runs — the same split as in Studio.
 *
 * @property-read array{rows: array<mixed>, error: string|null} $listing
 */
#[Layout('cipi-gui::layouts.app')]
class Infrastructure extends Component
{
    /** How long a list read from a provider stays fresh, in seconds. */
    private const FRESH = 120;

    #[Locked]
    public string $connector = '';

    /** uuid of the account open now */
    #[Url]
    public ?string $account = null;

    /** key of the section open now */
    #[Url]
    public ?string $tab = null;

    /** @var array<string, string> what a child list needs from its parent row (a zone's id) */
    #[Url]
    public array $scope = [];

    /** @var array<string, mixed> */
    public array $filters = [];

    public string $search = '';

    /** key of the action whose form is open */
    public ?string $formAction = null;

    /** id of the row it runs on; null for a list action */
    public ?string $formRow = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public ?string $resultTitle = null;

    public ?string $resultText = null;

    public ?string $resultPoll = null;

    public function mount(string $connector): void
    {
        abort_unless(Access::isAdmin(Auth::user()) && Connectors::find($connector) !== null, 404);

        $this->connector = $connector;

        if ($this->currentAccount() === null) {
            $this->account = $this->accounts()->first()?->uuid;
        }

        $section = $this->section();
        if ($section->isChild() && $this->scope === []) {
            $this->tab = $section->parent;
        }
        $this->tab = $this->section()->key;
        $this->filters = $this->defaultFilters($this->section());
    }

    /** An account removed, or a role taken away, closes the page at the next click. */
    public function hydrate(): void
    {
        abort_unless(Access::isAdmin(Auth::user()), 403);
    }

    #[Computed]
    public function definition(): Connector
    {
        return Connectors::make($this->connector);
    }

    /** @return Collection<int, CipiConnectorAccount> */
    #[Computed]
    public function accounts(): Collection
    {
        return CipiConnectorAccount::query()->where('connector', $this->connector)->orderBy('name')->get();
    }

    public function currentAccount(): ?CipiConnectorAccount
    {
        return $this->accounts()->firstWhere('uuid', $this->account);
    }

    public function section(): Section
    {
        $sections = $this->definition()->sections();

        return ($this->tab !== null ? $this->definition()->section($this->tab) : null) ?? $sections[0];
    }

    /** @return array<string, mixed> */
    protected function context(): array
    {
        return [...$this->filters, ...$this->scope];
    }

    /** @return array<string, mixed> */
    protected function defaultFilters(Section $section): array
    {
        $filters = [];
        foreach ($section->filters as $field) {
            $filters[$field->name] = $field->defaultFor([]) ?? '';
        }

        return $filters;
    }

    /**
     * The open list as the provider has it, or why it could not be read.
     *
     * @return array{rows: array<mixed>, error: string|null}
     */
    #[Computed]
    public function listing(): array
    {
        $account = $this->currentAccount();
        $section = $this->section();

        if ($account === null || $section->list === null) {
            return ['rows' => [], 'error' => null];
        }

        try {
            $sealed = Cache::remember(
                $account->cacheKey('rows', $section->key, (string) json_encode($this->context())),
                self::FRESH,
                fn () => encrypt(($section->list)($account->client(), $this->context())),
            );
            $rows = decrypt($sealed);

            return ['rows' => is_array($rows) ? $rows : [], 'error' => null];
        } catch (ConnectorException $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['rows' => [], 'error' => $this->unexpected($e)];
        }
    }

    /** @return list<array<string, mixed>> the rows that match the search box */
    protected function visibleRows(Section $section): array
    {
        $rows = array_values(array_filter($this->listing['rows'], 'is_array'));
        $search = mb_strtolower(trim($this->search));

        if ($search === '' || $section->kind !== 'table') {
            return $rows;
        }

        return array_values(array_filter($rows, function (array $row) use ($section, $search) {
            foreach ($section->columns as $column) {
                if (str_contains(mb_strtolower($column->text($row)), $search)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /** @return array<string, mixed>|null */
    protected function row(string $id): ?array
    {
        $section = $this->section();
        foreach ($this->listing['rows'] as $row) {
            if (is_array($row) && $section->rowId($row) === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The action and the row it runs on (empty for a list action); null when
     * the row is gone since the page was drawn.
     *
     * @return array{0: Action, 1: array<string, mixed>}|null
     */
    protected function locate(string $key, ?string $id): ?array
    {
        $section = $this->section();

        if ($id === null) {
            return [$section->toolbarAction($key) ?? abort(404), []];
        }

        $action = $section->action($key) ?? abort(404);
        $row = $this->row($id);

        if ($row === null || ! $action->appliesTo($row)) {
            $this->dispatch('notify', type: 'warning', message: 'That is no longer in the list. Refreshed.');
            $this->refresh();

            return null;
        }

        return [$action, $row];
    }

    protected function unexpected(\Throwable $e): string
    {
        report($e);

        return 'The panel could not make sense of the answer: '.$e->getMessage();
    }

    /** @return list<Action> the actions that apply to a row */
    public function rowActions(Section $section, array $row): array
    {
        return array_values(array_filter($section->actions, fn (Action $action) => $action->appliesTo($row)));
    }

    /** @return list<Section> the tabs: every list not opened from another one */
    protected function tabSections(): array
    {
        return array_values(array_filter($this->definition()->sections(), fn (Section $s) => ! $s->isChild()));
    }

    public function select(string $tab): void
    {
        $section = $this->definition()->section($tab);
        if ($section === null || $section->isChild()) {
            return;
        }

        $this->tab = $tab;
        $this->scope = [];
        $this->search = '';
        $this->filters = $this->defaultFilters($section);
        unset($this->listing);
    }

    public function selectGroup(string $group): void
    {
        foreach ($this->definition()->sections() as $section) {
            if (! $section->isChild() && $section->group === $group) {
                $this->select($section->key);

                return;
            }
        }
    }

    public function back(): void
    {
        $this->select((string) $this->section()->parent);
    }

    public function useAccount(string $uuid): void
    {
        if ($this->accounts()->firstWhere('uuid', $uuid) === null) {
            return;
        }

        $this->account = $uuid;
        // A zone or an app of one account means nothing on another.
        if ($this->section()->isChild()) {
            $this->back();
        }
        unset($this->listing);
    }

    public function updatedFilters(): void
    {
        unset($this->listing);
    }

    public function refresh(): void
    {
        $this->currentAccount()?->forget();
        unset($this->listing);
    }

    /** A click on an action: open its form, or a child list, or run it. */
    public function start(string $key, ?string $id = null): void
    {
        if (($located = $this->locate($key, $id)) === null) {
            return;
        }

        [$action, $row] = $located;

        if ($action->opens !== null && $action->scope !== null) {
            $this->tab = $action->opens;
            $this->scope = array_map('strval', ($action->scope)($row));
            $this->search = '';
            $this->filters = $this->defaultFilters($this->section());
            unset($this->listing);

            return;
        }

        if (! $action->hasForm()) {
            $this->execute($action, $row, []);

            return;
        }

        $this->formAction = $key;
        $this->formRow = $id;
        $this->form = [];

        foreach ($action->fields as $field) {
            $default = $field->defaultFor($row);
            $this->form[$field->name] = match ($field->type) {
                'multiselect' => array_map('strval', (array) $default),
                'checkbox' => (bool) $default,
                default => is_scalar($default) ? (string) $default : '',
            };
        }

        $this->resetValidation();
    }

    public function closeForm(): void
    {
        $this->reset('formAction', 'formRow', 'form');
        $this->resetValidation();
    }

    public function submit(): void
    {
        if ($this->formAction === null) {
            return;
        }

        if (($located = $this->locate($this->formAction, $this->formRow)) === null) {
            $this->closeForm();

            return;
        }

        [$action, $row] = $located;

        $this->validate(
            collect($action->fields)->mapWithKeys(fn (Field $field) => ['form.'.$field->name => $field->validation()])->all(),
            [],
            collect($action->fields)->mapWithKeys(fn (Field $field) => ['form.'.$field->name => mb_strtolower($field->label)])->all(),
        );

        $data = [];
        foreach ($action->fields as $field) {
            $data[$field->name] = $field->cast($this->form[$field->name] ?? null);
        }

        if ($this->execute($action, $row, $data)) {
            $this->closeForm();
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $data
     */
    private function execute(Action $action, array $row, array $data): bool
    {
        $account = $this->currentAccount();
        if ($account === null || $action->run === null) {
            return false;
        }

        $section = $this->section();

        Log::info('cipi-gui.infrastructure', [
            'user' => Auth::id(),
            'connector' => $this->connector,
            'account' => $account->uuid,
            'section' => $section->key,
            'action' => $action->key,
            'row' => $row === [] ? null : $section->rowId($row),
        ]);

        try {
            $outcome = Outcome::from(($action->run)($account->client(), $row, $data, $this->context()));
        } catch (ConnectorException $e) {
            $this->dispatch('notify', type: 'error', message: $e->getMessage());

            return false;
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $this->unexpected($e));

            return false;
        } finally {
            $account->forget();
            unset($this->listing);
        }

        $this->present($outcome);

        return true;
    }

    private function present(Outcome $outcome): void
    {
        if ($outcome->message !== null) {
            $this->dispatch('notify', type: 'success', message: $outcome->message);
        }

        if ($outcome->data === null && $outcome->poll === null) {
            return;
        }

        $this->resultTitle = $outcome->title ?? 'Result';
        $this->resultText = match (true) {
            $outcome->data === null => '',
            is_string($outcome->data) => $outcome->data,
            is_scalar($outcome->data) => (string) $outcome->data,
            default => (string) json_encode($outcome->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
        $this->resultPoll = $outcome->poll;
    }

    public function closeResult(): void
    {
        $this->reset('resultTitle', 'resultText', 'resultPoll');
    }

    /** Ask again about work the provider runs in the background. */
    public function poll(): void
    {
        $account = $this->currentAccount();
        if ($account === null || $this->resultPoll === null) {
            return;
        }

        try {
            $outcome = $this->definition()->poll($account->client(), $this->resultPoll);
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: $e instanceof ConnectorException ? $e->getMessage() : $this->unexpected($e));

            return;
        }

        if ($outcome->poll === null) {
            $account->forget();
            unset($this->listing);
        }

        $this->present($outcome);
    }

    /**
     * The choices of a select (or the suggestions of a field) in the open
     * form, from the provider when they come from there.
     *
     * @return array{options: array<array-key, string>, error: string|null}
     */
    public function options(Field $field): array
    {
        if (is_array($field->options)) {
            return ['options' => $field->options, 'error' => null];
        }

        $account = $this->currentAccount();
        if ($field->options === null || $account === null) {
            return ['options' => [], 'error' => null];
        }

        // A server of the panel as a DNS target: read here, never cached with the provider's answers.
        if ($field->type === 'suggest') {
            try {
                $options = ($field->options)(null, $this->context());

                return ['options' => is_array($options) ? array_map('strval', $options) : [], 'error' => null];
            } catch (\Throwable $e) {
                return ['options' => [], 'error' => null];
            }
        }

        try {
            $sealed = Cache::remember(
                $account->cacheKey('options', $this->section()->key, (string) $this->formAction, $field->name, (string) json_encode($this->context())),
                self::FRESH * 5,
                fn () => encrypt(($field->options)($account->client(), $this->context())),
            );
            $options = decrypt($sealed);

            return ['options' => is_array($options) ? array_map('strval', $options) : [], 'error' => null];
        } catch (ConnectorException $e) {
            return ['options' => [], 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['options' => [], 'error' => $this->unexpected($e)];
        }
    }

    public function render()
    {
        $definition = $this->definition();
        $section = $this->section();
        $formAction = null;

        if ($this->formAction !== null) {
            $formAction = $this->formRow === null ? $section->toolbarAction($this->formAction) : $section->action($this->formAction);
        }

        $tabs = $this->tabSections();
        $groups = array_values(array_unique(array_filter(array_map(fn (Section $s) => $s->group, $tabs))));
        $group = $section->group ?? ($section->parent !== null ? $definition->section($section->parent)?->group : null);
        $isTab = in_array($section->key, array_map(fn (Section $s) => $s->key, $tabs), true);
        $current = $this->currentAccount();

        return view('cipi-gui::livewire.infrastructure', [
            'definition' => $definition,
            'groups' => $groups,
            'group' => $group,
            'tabs' => $groups === [] ? $tabs : array_values(array_filter($tabs, fn (Section $s) => $s->group === $group)),
            'section' => $section,
            'current' => $current,
            'rows' => match (true) {
                $current === null => [],
                $section->kind === 'info' => $this->listing['rows'],
                default => $this->visibleRows($section),
            },
            'error' => $current !== null ? $this->listing['error'] : null,
            'parent' => ! $isTab && $section->parent !== null ? $definition->section($section->parent) : null,
            'formFields' => $formAction->fields ?? [],
            'formLabel' => $formAction->label ?? '',
            'formConfirm' => $formAction?->confirm,
            'formDanger' => (bool) ($formAction->danger ?? false),
        ])->title($definition->label());
    }
}
