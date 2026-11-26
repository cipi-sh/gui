@php
    use Illuminate\Support\Js;
    use Illuminate\Support\Str;
    $badge = fn (string $color) => match ($color) {
        'green' => 'badge-green', 'red' => 'badge-red', 'yellow', 'amber', 'orange' => 'badge-amber',
        'blue', 'sky' => 'badge-blue', default => 'badge-gray',
    };
@endphp
<div data-infrastructure="{{ $definition->key() }}">
    <x-cipi::page-header :title="$definition->label()" :subtitle="$definition->description()">
        <x-slot:actions>
            @if($current)
                <button type="button" wire:click="refresh" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="refresh"><x-cipi::icon name="refresh" /> Refresh</button>
            @endif
            <a href="{{ route('cipi-gui.settings') }}#connectors" class="btn btn-ghost"><x-cipi::icon name="settings" /> Accounts</a>
        </x-slot:actions>
    </x-cipi::page-header>

    @if($this->accounts->isEmpty())
        <div class="card">
            <x-cipi::empty icon="cloud" title="No {{ $definition->label() }} account yet">
                Add one in Settings → Connectors: the panel keeps its credentials encrypted and shows here what it reaches.
                <x-slot:actions>
                    <a href="{{ route('cipi-gui.settings') }}#connectors" class="btn btn-primary btn-sm"><x-cipi::icon name="plus" /> Add an account</a>
                </x-slot:actions>
            </x-cipi::empty>
        </div>
    @else
        @if($this->accounts->count() > 1)
            <div class="segmented mb-4" role="tablist" aria-label="Accounts" data-infra-accounts>
                @foreach($this->accounts as $acc)
                    <button type="button" wire:click="useAccount('{{ $acc->uuid }}')" wire:key="acc-{{ $acc->uuid }}" role="tab"
                            class="segmented-item {{ $current?->uuid === $acc->uuid ? 'active' : '' }}" aria-selected="{{ $current?->uuid === $acc->uuid ? 'true' : 'false' }}"
                            title="{{ $acc->status }}">
                        <span class="dot {{ $acc->isHealthy() ? 'dot-green' : ($acc->status ? 'dot-red' : '') }}" aria-hidden="true"></span> {{ $acc->name }}
                    </button>
                @endforeach
            </div>
        @endif

        @if($groups !== [])
            <nav class="tabs mb-2" aria-label="{{ $definition->label() }} services">
                @foreach($groups as $g)
                    <button type="button" wire:click="selectGroup({{ Js::from($g) }})" wire:key="grp-{{ md5($g) }}" class="tab-btn {{ $group === $g ? 'active' : '' }}">{{ $g }}</button>
                @endforeach
            </nav>
        @endif
        <nav class="tabs" aria-label="{{ $definition->label() }} lists" data-infra-tabs>
            @foreach($tabs as $t)
                <button type="button" wire:click="select('{{ $t->key }}')" wire:key="tab-{{ $t->key }}"
                        class="tab-btn {{ ($section->key === $t->key || $section->parent === $t->key) ? 'active' : '' }}"
                        @if($section->key === $t->key) aria-current="page" @endif>
                    <span class="inline-flex items-center gap-2"><x-cipi::icon :name="$t->icon" /> {{ $t->label }}</span>
                </button>
            @endforeach
        </nav>

        <div class="card card-flush mt-4" data-infra-section="{{ $section->key }}">
            <div class="card-header p-5 mb-0">
                <div class="min-w-0">
                    @if($parent)
                        <button type="button" wire:click="back" class="btn btn-ghost btn-sm mb-2" data-infra-back><x-cipi::icon name="arrow-left" /> {{ $parent->label }}</button>
                        <h2 class="card-title">{{ $section->label }} <span class="text-soft font-mono text-sm">· {{ $scope[$section->parentLabel] ?? '' }}</span></h2>
                    @else
                        <h2 class="card-title flex items-center gap-2"><x-cipi::icon :name="$section->icon" /> {{ $section->label }}</h2>
                    @endif
                    @if($section->description)
                        <p class="card-subtitle">{{ $section->description }}</p>
                    @endif
                    <p class="card-subtitle">{{ $current?->name }} · read from {{ $definition->label() }}, kept for two minutes</p>
                </div>
                <div class="infra-toolbar">
                    @foreach($section->filters as $filter)
                        @if($filter->type === 'text')
                            <input type="search" wire:model.live.debounce.500ms="filters.{{ $filter->name }}" placeholder="{{ $filter->placeholder ?? $filter->label }}" aria-label="{{ $filter->label }}" class="search-input infra-filter" wire:key="flt-{{ $section->key }}-{{ $filter->name }}">
                            @continue
                        @endif
                        @php $opts = $this->options($filter); @endphp
                        <select wire:model.live="filters.{{ $filter->name }}" aria-label="{{ $filter->label }}" class="infra-filter" wire:key="flt-{{ $section->key }}-{{ $filter->name }}">
                            @if($filter->help)<option value="">{{ $filter->label }}: {{ $filter->help }}</option>@endif
                            @foreach($opts['options'] as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @endforeach
                    @if($section->kind === 'table')
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search" class="search-input infra-search" aria-label="Search {{ $section->label }}">
                    @endif
                    @foreach($section->toolbar as $action)
                        <button type="button" wire:click="start('{{ $action->key }}')" wire:key="tb-{{ $section->key }}-{{ $action->key }}"
                                class="btn btn-sm {{ $action->primary ? 'btn-primary' : 'btn-secondary' }}"
                                @if(! $action->hasForm() && $action->confirm) data-confirm="{{ $action->confirm }}" @if($action->danger) data-confirm-danger @endif @endif>
                            <x-cipi::icon :name="$action->icon" /> {{ $action->label }}
                        </button>
                    @endforeach
                </div>
            </div>

            @if($error)
                <div class="px-5 pb-5">
                    <x-cipi::alert type="danger" title="{{ $definition->label() }} did not answer as expected" data-infra-error>
                        <span class="font-mono text-xs break-words">{{ $error }}</span>
                    </x-cipi::alert>
                </div>
            @elseif($section->kind === 'info')
                <div class="px-5 pb-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @foreach($rows as $title => $values)
                        <div class="infra-info" wire:key="info-{{ md5((string) $title) }}">
                            <h3 class="text-sm text-strong">{{ $title }}</h3>
                            @if(is_array($values))
                                <dl class="mt-2 space-y-1 text-sm">
                                    @forelse($values as $label => $value)
                                        <div class="flex items-start justify-between gap-3">
                                            <dt class="text-soft">{{ is_int($label) ? '' : $label }}</dt>
                                            <dd class="min-w-0 text-right font-mono text-xs break-all">{{ match (true) { is_bool($value) => $value ? 'yes' : 'no', $value === null => '—', is_scalar($value) => $value, default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) } }}</dd>
                                        </div>
                                    @empty
                                        <p class="text-xs text-soft">Nothing here.</p>
                                    @endforelse
                                </dl>
                            @else
                                <div class="mt-2 font-mono text-sm">{{ $values }}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="table-scroll" wire:loading.class="opacity-60" wire:target="select, useAccount, refresh, back, filters, start, submit">
                    <table class="table-compact" data-infra-table>
                        <thead>
                            <tr>
                                @foreach($section->columns as $column)
                                    <th>{{ $column->label }}</th>
                                @endforeach
                                <th class="text-right"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $row)
                                @php
                                    $id = $section->rowId($row);
                                    $actions = $this->rowActions($section, $row);
                                    $opens = array_values(array_filter($actions, fn ($a) => $a->opens));
                                    $menu = array_values(array_filter($actions, fn ($a) => ! $a->opens));
                                @endphp
                                <tr wire:key="row-{{ md5($section->key.'|'.$id) }}">
                                    @foreach($section->columns as $column)
                                        @php $text = $column->text($row); $href = $column->href($row); @endphp
                                        <td>
                                            @if($href && $text !== '')
                                                <a href="{{ $href }}" target="_blank" rel="noopener noreferrer" class="row-link">{{ Str::limit($text, 160) }}</a>
                                            @elseif($text === '')
                                                <span class="text-subtle">—</span>
                                            @elseif($column->format === 'badge')
                                                <span class="badge {{ $badge($column->color($row)) }}">{{ $text }}</span>
                                            @elseif($column->format === 'bool')
                                                <span class="badge {{ $column->value($row) ? 'badge-green' : 'badge-gray' }}">{{ $text }}</span>
                                            @elseif($column->format === 'mono')
                                                <span class="font-mono text-xs {{ mb_strlen($text) > 40 ? 'break-all' : '' }}">{{ Str::limit($text, 160) }}</span>
                                            @elseif($column->format === 'date')
                                                <span class="text-xs text-soft" title="{{ is_scalar($column->value($row)) ? $column->value($row) : '' }}">{{ $text }}</span>
                                            @else
                                                <span class="{{ $loop->first ? 'text-strong' : '' }}">{{ Str::limit($text, 160) }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="text-right">
                                        <div class="btn-actions">
                                            @foreach($opens as $action)
                                                <button type="button" wire:click="start({{ Js::from($action->key) }}, {{ Js::from($id) }})" class="btn btn-ghost btn-sm"><x-cipi::icon :name="$action->icon" /> {{ $action->label }}</button>
                                            @endforeach
                                            @if($menu !== [])
                                                <div class="dropdown" x-data="{ open: false }" x-on:click.outside="open = false">
                                                    <button type="button" class="btn btn-ghost btn-icon btn-sm" x-on:click="open = !open" aria-label="Actions"><x-cipi::icon name="more" /></button>
                                                    <div class="dropdown-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms>
                                                        @foreach($menu as $action)
                                                            @if($action->danger && ! $loop->first)<div class="dropdown-divider"></div>@endif
                                                            <button type="button" class="dropdown-item {{ $action->danger ? 'danger' : '' }}" x-on:click="open = false"
                                                                    wire:click="start({{ Js::from($action->key) }}, {{ Js::from($id) }})"
                                                                    @if(! $action->hasForm() && $action->confirm) data-confirm="{{ $action->confirm }}" @if($action->danger) data-confirm-danger @endif @endif>
                                                                <x-cipi::icon :name="$action->icon" /> {{ $action->label }}
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($section->columns) + 1 }}" class="text-center text-soft py-8">
                                        {{ trim($search) !== '' ? 'Nothing matches the search.' : 'Nothing here yet.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if(count($rows) > 0)
                    <p class="text-xs text-subtle px-5 py-3">{{ count($rows) }} {{ Str::plural('item', count($rows)) }}</p>
                @endif
            @endif
        </div>
    @endif

    @if($formAction !== null)
        <x-cipi::modal :title="$formLabel" close="closeForm" persistent :subtitle="$formConfirm" data-infra-form>
            <form wire:submit="submit" novalidate>
                <div class="modal-body space-y-4">
                    @foreach($formFields as $field)
                        @php $fid = 'infra-'.$field->name; @endphp
                        <div wire:key="fld-{{ $formAction }}-{{ $field->name }}">
                            @if($field->type === 'checkbox')
                                <label class="check-card">
                                    <input type="checkbox" wire:model="form.{{ $field->name }}">
                                    <span><span class="check-card-title">{{ $field->label }}</span>@if($field->help)<span class="check-card-text">{{ $field->help }}</span>@endif</span>
                                </label>
                            @else
                                <label for="{{ $fid }}">{{ $field->label }}@unless($field->required) <span class="text-subtle font-normal">(optional)</span>@endunless</label>
                                @if($field->type === 'textarea')
                                    <textarea id="{{ $fid }}" rows="4" wire:model="form.{{ $field->name }}" placeholder="{{ $field->placeholder }}" class="font-mono text-xs"></textarea>
                                @elseif($field->type === 'select' || $field->type === 'multiselect')
                                    @php $opts = $this->options($field); @endphp
                                    <select id="{{ $fid }}" wire:model="form.{{ $field->name }}" @if($field->type === 'multiselect') multiple size="5" @endif>
                                        @if($field->type === 'select' && ! $field->required)<option value="">—</option>@endif
                                        @if($field->type === 'select' && $field->required && ! array_key_exists((string) ($form[$field->name] ?? ''), $opts['options']))<option value="">Choose…</option>@endif
                                        @foreach($opts['options'] as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @if($opts['error'])<p class="field-error">{{ $opts['error'] }}</p>@endif
                                @elseif($field->type === 'suggest')
                                    @php $opts = $this->options($field); @endphp
                                    <input id="{{ $fid }}" type="text" list="{{ $fid }}-list" wire:model="form.{{ $field->name }}" placeholder="{{ $field->placeholder ?? ($opts['options'] !== [] ? 'An address, or a server: '.implode(', ', array_slice(array_keys($opts['options']), 0, 3)) : '') }}" autocomplete="off" class="font-mono" data-infra-suggest>
                                    <datalist id="{{ $fid }}-list">
                                        @foreach($opts['options'] as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </datalist>
                                @else
                                    <input id="{{ $fid }}" type="{{ $field->type === 'number' ? 'number' : ($field->type === 'password' ? 'password' : 'text') }}" wire:model="form.{{ $field->name }}" placeholder="{{ $field->placeholder }}" autocomplete="off">
                                @endif
                                @if($field->help)<p class="field-hint">{{ $field->help }}</p>@endif
                            @endif
                            @error('form.'.$field->name) <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
                <div class="modal-footer">
                    <button type="button" wire:click="closeForm" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn {{ $formDanger ? 'btn-danger-solid' : 'btn-primary' }}" wire:loading.attr="disabled" wire:target="submit">
                        <span wire:loading.remove wire:target="submit">{{ $formLabel }}</span>
                        <span wire:loading wire:target="submit" class="inline-flex items-center gap-2"><span class="spinner"></span> Working…</span>
                    </button>
                </div>
            </form>
        </x-cipi::modal>
    @endif

    @if($resultTitle !== null)
        <x-cipi::modal :title="$resultTitle" close="closeResult" size="lg" data-infra-result>
            <div class="modal-body">
                @if($resultText !== '')
                    <pre class="infra-result">{{ $resultText }}</pre>
                    <p class="mt-3"><x-cipi::copy :value="$resultText" label="Copy" /></p>
                @endif
                @if($resultPoll)
                    <p class="text-sm text-soft mt-3">{{ $definition->label() }} is still working on it.</p>
                @endif
            </div>
            <div class="modal-footer">
                @if($resultPoll)
                    <button type="button" wire:click="poll" class="btn btn-secondary"><x-cipi::icon name="refresh" /> Check again</button>
                @endif
                <button type="button" wire:click="closeResult" class="btn btn-primary">Done</button>
            </div>
        </x-cipi::modal>
    @endif
</div>
