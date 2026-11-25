@php
    use CipiGui\Support\Notifications as N;
    $on = (int) ($summary['enabled'] ?? 0);
    $total = (int) ($summary['total'] ?? 0);
    $active = count(array_filter($channels, fn ($c) => ! empty($c['enabled'])));
    $editingChannel = $editing !== null ? $this->channel($editing) : null;
@endphp
<div wire:init="init" data-notifications>
    @if(! $loaded)
        <div class="card" aria-busy="true" data-notifications-loading>
            <div class="space-y-3">
                <div class="skeleton h-4 w-40"></div>
                <div class="skeleton h-12 w-full"></div>
                <div class="skeleton h-12 w-full"></div>
                <div class="skeleton h-4 w-1/2"></div>
            </div>
            <p class="sr-only">Loading notifications from the server…</p>
        </div>
    @elseif($unsupported)
        <x-cipi::alert type="warn" title="Notifications are not available on this server" data-notifications-unsupported>
            Channels and triggers need an up-to-date Cipi on the server:
            <code>cipi self-update &amp;&amp; cipi api update</code>.
            @if($unsupportedReason !== '')
                <p class="mt-2">The server answered: <span class="font-mono text-xs break-words">{{ $unsupportedReason }}</span></p>
            @endif
            <p class="mt-3"><button type="button" wire:click="load" class="btn btn-secondary btn-sm"><x-cipi::icon name="refresh" /> Check again</button></p>
        </x-cipi::alert>
    @elseif($error !== null && $channels === [] && $triggers === [])
        <x-cipi::alert type="danger" title="Notifications could not be read" data-tab-error>
            <span class="font-mono text-xs break-words">{{ $error }}</span>
            <p class="mt-3"><button type="button" wire:click="load" class="btn btn-secondary btn-sm"><x-cipi::icon name="refresh" /> Try again</button></p>
        </x-cipi::alert>
    @else
        <div class="space-y-4">
            {{-- ── Channels ───────────────────────────────────────── --}}
            <div class="card card-flush" data-notification-channels>
                <div class="card-header p-5 mb-0">
                    <div>
                        <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="signal" /> Channels</h2>
                        <p class="card-subtitle">Where notifications go. A trigger that is on reaches every channel that is on and takes it — everything, or only the events you choose per channel · {{ $active }} of {{ count($channels) }} on · secrets are never sent back to the panel.</p>
                    </div>
                    @if($canManage)
                        <div class="btn-group">
                            @if(count($channels) > 1)
                                <button type="button" wire:click="testChannel('all')" class="btn btn-secondary btn-sm" wire:loading.attr="disabled" wire:target="testChannel"><x-cipi::icon name="play" /> Test all</button>
                            @endif
                            <button type="button" wire:click="openAdd('slack')" class="btn btn-primary btn-sm" data-add-channel><x-cipi::icon name="plus" /> Add channel</button>
                        </div>
                    @endif
                </div>
                @if($channels === [])
                    <x-cipi::empty icon="signal" title="No channels yet" class="pb-5">
                        Nothing is sent: every event is only written to /var/log/cipi/events.log. Add an email, a chat or a push channel.
                        @if($canManage)
                            <x-slot:actions>
                                <button type="button" wire:click="openAdd('smtp')" class="btn btn-secondary btn-sm"><x-cipi::icon name="mail" /> Email</button>
                                <button type="button" wire:click="openAdd('slack')" class="btn btn-secondary btn-sm"><x-cipi::icon name="bolt" /> Slack</button>
                                <button type="button" wire:click="openAdd('telegram')" class="btn btn-secondary btn-sm"><x-cipi::icon name="signal" /> Telegram</button>
                            </x-slot:actions>
                        @endif
                    </x-cipi::empty>
                @else
                    <div class="table-scroll">
                        <table>
                            <thead><tr><th>Channel</th><th>Type</th><th>Sends to</th>@if($this->supportsEvents())<th>Receives</th>@endif<th></th></tr></thead>
                            <tbody>
                                @foreach($channels as $ch)
                                    @php $cid = (string) $ch['id']; $test = $tests[$cid] ?? null; @endphp
                                    <tr class="{{ ! empty($ch['enabled']) ? '' : 'opacity-60' }}" wire:key="ch-{{ $cid }}" data-channel="{{ $cid }}">
                                        <td>
                                            <span class="flex items-center gap-2">
                                                <span class="dot {{ ! empty($ch['enabled']) ? 'dot-green' : '' }}" aria-hidden="true"></span>
                                                <span class="font-mono text-strong">{{ $cid }}</span>
                                                @if(empty($ch['enabled']))<span class="badge badge-gray">paused</span>@endif
                                            </span>
                                        </td>
                                        <td><span class="flex items-center gap-2 text-soft"><x-cipi::icon :name="N::icon((string) $ch['type'])" /> {{ N::label((string) $ch['type']) }}</span></td>
                                        <td class="text-xs text-muted font-mono break-all">
                                            {{ N::target($ch) ?: '—' }}
                                            @if($test)
                                                <p class="mt-1 font-sans {{ ! empty($test['ok']) ? 'text-success' : 'text-danger' }}" data-test-result="{{ ! empty($test['ok']) ? 'ok' : 'fail' }}">
                                                    {{ ! empty($test['ok']) ? '✓ Test sent' : '✗ '.($test['message'] ?? 'Test failed') }}
                                                </p>
                                            @endif
                                        </td>
                                        @if($this->supportsEvents())
                                            <td class="text-xs" data-channel-events="{{ N::events($ch) === null ? 'all' : implode(',', N::events($ch)) }}">
                                                @if(N::events($ch) === null)
                                                    <span class="text-muted">Everything</span>
                                                @else
                                                    <span class="flex flex-wrap gap-1">
                                                        @foreach(N::events($ch) as $e)
                                                            <span class="badge {{ $e === N::URGENT ? 'badge-amber' : 'badge-neutral' }}">{{ $e === N::URGENT ? 'Urgent' : (array_column($categories, 'label', 'id')[$e] ?? $e) }}</span>
                                                        @endforeach
                                                    </span>
                                                @endif
                                            </td>
                                        @endif
                                        <td class="text-right">
                                            @if($canManage)
                                                <div class="btn-actions">
                                                    <button type="button" wire:click="testChannel('{{ $cid }}')" class="btn btn-ghost btn-xs" wire:loading.attr="disabled" wire:target="testChannel('{{ $cid }}')" title="Send a test notification">Test</button>
                                                    <button type="button" wire:click="openEdit('{{ $cid }}')" class="btn btn-ghost btn-xs"><x-cipi::icon name="pencil" /> Edit</button>
                                                    <button type="button" wire:click="toggleChannel('{{ $cid }}')" class="btn btn-ghost btn-xs">{{ ! empty($ch['enabled']) ? 'Pause' : 'Turn on' }}</button>
                                                    <button type="button" wire:click="removeChannel('{{ $cid }}')" data-confirm="Remove the channel {{ $cid }}? Its settings and secrets are deleted from the server." class="btn btn-ghost btn-xs danger" aria-label="Remove {{ $cid }}"><x-cipi::icon name="trash" /></button>
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @if(! $canManage)
                    <p class="field-hint px-5 pb-5">Changing channels and triggers needs Manage access to this server.</p>
                @endif
            </div>

            {{-- ── Triggers ───────────────────────────────────────── --}}
            <div class="card" data-notification-triggers>
                <div class="card-header">
                    <div>
                        <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="bolt" /> Triggers <span class="badge badge-neutral tabular-nums" data-triggers-on>{{ $on }} / {{ $total }} on</span></h2>
                        <p class="card-subtitle">Which events notify. Muted events are still written to <code>/var/log/cipi/events.log</code>.</p>
                    </div>
                    @if($canManage)
                        <div class="btn-group">
                            <button type="button" wire:click="setAllTriggers(true)" class="btn btn-ghost btn-sm">All on</button>
                            <button type="button" wire:click="setAllTriggers(false)" class="btn btn-ghost btn-sm">All off</button>
                            <button type="button" wire:click="resetTriggers" data-confirm="Turn every trigger back on, now?" class="btn btn-ghost btn-sm"><x-cipi::icon name="rollback" /> Defaults</button>
                        </div>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-3 mb-4">
                    <div class="flex-1 min-w-0 search-input">
                        <x-cipi::icon name="search" />
                        <label for="trigger-search" class="sr-only">Filter triggers</label>
                        <input id="trigger-search" type="search" wire:model.live.debounce.250ms="triggerSearch" placeholder="Filter: deploy, ssh, backup, monitor…" autocomplete="off">
                    </div>
                    @if($canManage && $changed !== [])
                        <div class="flex items-center gap-2" data-triggers-dirty="{{ count($changed) }}">
                            <span class="text-xs text-warn">{{ count($changed) }} unsaved change{{ count($changed) === 1 ? '' : 's' }}</span>
                            <button type="button" wire:click="discardTriggers" class="btn btn-ghost btn-sm">Discard</button>
                            <button type="button" wire:click="saveTriggers" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="saveTriggers"><x-cipi::icon name="check" /> Save</button>
                        </div>
                    @endif
                </div>

                @if($groups === [])
                    <p class="text-muted text-sm">No trigger matches “{{ $triggerSearch }}”.</p>
                @else
                    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4" @if(! $canManage) inert @endif>
                        @foreach($groups as $category => $list)
                            @php $catOn = count(array_filter($list, fn ($t) => ! empty($triggerState[$t['id']]))); @endphp
                            <div class="trigger-group" wire:key="cat-{{ $category }}" data-trigger-category="{{ $category }}">
                                <div class="trigger-group-head">
                                    <h3>{{ $category }} <span class="text-subtle tabular-nums">{{ $catOn }}/{{ count($list) }}</span></h3>
                                    @if($canManage)
                                        <span class="btn-group gap-1">
                                            <button type="button" wire:click="setCategory(@js($category), true)" class="btn btn-ghost btn-xs">all</button>
                                            <button type="button" wire:click="setCategory(@js($category), false)" class="btn btn-ghost btn-xs">none</button>
                                        </span>
                                    @endif
                                </div>
                                <div class="trigger-list">
                                    @foreach($list as $t)
                                        @php $tid = $t['id']; $dirty = array_key_exists($tid, $changed); @endphp
                                        <label class="trigger-row {{ $dirty ? 'is-dirty' : '' }}" wire:key="t-{{ $tid }}" title="{{ $tid }}">
                                            <input type="checkbox" class="switch" wire:model.live="triggerState.{{ $tid }}" @disabled(! $canManage)>
                                            <span class="min-w-0">
                                                <span class="trigger-label">{{ $t['label'] ?? $tid }}</span>
                                                <span class="trigger-id">{{ $tid }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- ── Add / edit a channel ──────────────────────────────── --}}
        @if($formOpen && $canManage)
            @php $fields = N::FIELDS[$formType] ?? []; @endphp
            <x-cipi::modal :title="$editing === null ? 'Add a notification channel' : 'Edit '.$editing" close="closeForm" size="lg" persistent
                :subtitle="$editing === null ? 'The triggers that are on reach it as soon as it is saved — all of them, or the events you choose below.' : N::label($formType).' · secrets left empty keep their stored value.'">
                <form wire:submit="saveChannel" data-channel-form="{{ $formType }}">
                    <div class="modal-body space-y-5">
                        @if($editing === null)
                            <div>
                                <label>Type</label>
                                <div class="type-picker channel-type-picker" role="radiogroup">
                                    @foreach(N::TYPES as $type => [$label, $hint, $icon])
                                        @php $taken = $type === 'smtp' && $this->hasSmtp(); @endphp
                                        <button type="button" role="radio" aria-checked="{{ $formType === $type ? 'true' : 'false' }}" wire:click="setFormType('{{ $type }}')"
                                            class="type-option {{ $formType === $type ? 'active' : '' }} {{ $taken ? 'opacity-50 cursor-not-allowed' : '' }}" @disabled($taken) data-type="{{ $type }}">
                                            <strong class="flex items-center gap-2"><x-cipi::icon :name="$icon" /> {{ $label }}</strong>
                                            <span>{{ $taken ? 'Already set — edit it from the list.' : $hint }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            @if($formType !== 'smtp')
                                <div>
                                    <label for="ch-id">Channel id</label>
                                    <input id="ch-id" type="text" wire:model="formId" class="font-mono" placeholder="ops" autocomplete="off" spellcheck="false" @disabled($editing !== null)>
                                    <p class="field-hint">How it is named in <code>cipi notification</code>: lowercase letters, digits, dashes.</p>
                                    @error('formId') <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                            @endif
                            @foreach($fields as $field => [$label, $input, $placeholder, $required, $hint])
                                @php
                                    $secret = N::isSecret($field);
                                    $stored = $editing !== null && $secret && $editingChannel !== null && N::secretStored($editingChannel, $field);
                                    $wide = in_array($field, ['url', 'to', 'from', 'host', 'api_key', 'secret_key', 'token'], true) && $formType !== 'smtp';
                                @endphp
                                <div class="{{ $wide ? 'sm:col-span-2' : '' }}">
                                    <label for="ch-{{ $field }}">{{ $label }}@if(! $required || $stored) <span class="text-subtle font-normal">{{ $stored ? '· stored' : '· optional' }}</span>@endif</label>
                                    @if($input === 'select')
                                        <select id="ch-{{ $field }}" wire:model="form.{{ $field }}">
                                            @foreach(N::PRIORITIES as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                                        </select>
                                    @else
                                        <input id="ch-{{ $field }}" type="{{ $input }}" wire:model="form.{{ $field }}"
                                            placeholder="{{ $stored ? 'Unchanged — type to replace' : $placeholder }}"
                                            autocomplete="{{ $input === 'password' ? 'new-password' : 'off' }}" spellcheck="false"
                                            class="{{ in_array($field, ['url', 'token', 'api_key', 'secret_key', 'secret', 'chat_id', 'access_key', 'region', 'host'], true) ? 'font-mono' : '' }}"
                                            @if($input === 'number') min="1" max="65535" @endif>
                                    @endif
                                    @if($hint)<p class="field-hint">{{ $hint }}</p>@endif
                                    @error('form.'.$field) <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                            @endforeach
                        </div>

                        @if($this->supportsEvents())
                            <div data-channel-events-form>
                                <label>What it receives</label>
                                <div class="segmented mb-3" role="radiogroup" aria-label="What this channel receives">
                                    <button type="button" role="radio" aria-checked="{{ $formEventsMode === 'all' ? 'true' : 'false' }}" wire:click="$set('formEventsMode', 'all')" class="segmented-item {{ $formEventsMode === 'all' ? 'active' : '' }}">Everything</button>
                                    <button type="button" role="radio" aria-checked="{{ $formEventsMode === 'some' ? 'true' : 'false' }}" wire:click="$set('formEventsMode', 'some')" class="segmented-item {{ $formEventsMode === 'some' ? 'active' : '' }}">Only some events</button>
                                </div>
                                @if($formEventsMode === 'some')
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                        <label class="check" title="Failures and security events: a failed deploy, backup or cron, a health check down, a service or a worker stopped, the disk filling up, an SSH login, sudo, malware found…">
                                            <input type="checkbox" wire:model="formEvents.{{ N::URGENT }}"> <span><strong>Urgent</strong> <span class="text-subtle">— failures and security</span></span>
                                        </label>
                                        @foreach($categories as $cat)
                                            <label class="check" wire:key="ev-{{ $cat['id'] }}">
                                                <input type="checkbox" wire:model="formEvents.{{ $cat['id'] }}"> <span>{{ $cat['label'] }} <span class="text-subtle tabular-nums">{{ (int) ($cat['triggers'] ?? 0) }}</span></span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @if($formEventsExtra !== [])
                                        <p class="field-hint mt-2">Also, set at the server: @foreach($formEventsExtra as $e)<code>{{ $e }}</code>@if(! $loop->last), @endif @endforeach — kept as they are.</p>
                                    @endif
                                    <p class="field-hint mt-2">A trigger reaches this channel when it is on (Triggers, below) and is in one of the kinds chosen here. For a single trigger: <code>cipi notification events {{ $formType === 'smtp' ? 'smtp' : ($formId ?: '<id>') }} backup_fail,…</code> at the server.</p>
                                @endif
                                @error('formEvents') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center gap-4">
                            @if($formType === 'smtp')
                                <label class="check"><input type="checkbox" wire:model="formTls"> STARTTLS / TLS</label>
                            @endif
                            @if($formType === 'webhook' && $editing !== null && $editingChannel !== null && ! empty($editingChannel['secret_set']))
                                <label class="check"><input type="checkbox" wire:model="formClearSecret"> Stop signing (remove the secret)</label>
                            @endif
                            <label class="check"><input type="checkbox" wire:model="formEnabled"> On</label>
                            <label class="check"><input type="checkbox" wire:model="formTest"> Send a test after saving</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" wire:click="closeForm" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveChannel">
                            <x-cipi::icon name="{{ $editing === null ? 'plus' : 'check' }}" /> {{ $editing === null ? 'Add '.N::label($formType) : 'Save' }}
                        </button>
                    </div>
                </form>
            </x-cipi::modal>
        @endif
    @endif
</div>
