<div class="card" id="connectors" data-connectors>
    <div class="card-header">
        <div>
            <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="cloud" /> Connectors</h2>
            <p class="card-subtitle">The providers around your servers, driven from here: clouds and VPS, domains and DNS — records pointed at a server of the panel by its name — object storage, email, monitoring and security scanners. Admins only. Each account is checked with the provider before it is saved and kept encrypted; a connector with an account appears under Infrastructure in the sidebar.</p>
        </div>
    </div>
    @foreach($groups as $category => $entries)
        <h3 class="connector-group" data-connector-group="{{ $category }}">{{ \CipiGui\Infrastructure\Connectors::CATEGORIES[$category] ?? $category }}</h3>
        <div class="connector-grid">
            @foreach($entries as $entry)
                @php $connector = $entry['connector']; $key = $connector->key(); $n = (int) ($counts[$key] ?? 0); @endphp
                <button type="button" wire:click="open('{{ $key }}')" class="connector-tile" wire:key="conn-{{ $category }}-{{ $key }}" data-connector="{{ $key }}">
                    <span class="connector-tile-head">
                        <span class="connector-tile-name"><x-cipi::icon :name="$connector->icon()" /> {{ $connector->label() }}</span>
                        <span class="badge {{ $n > 0 ? 'badge-green' : 'badge-neutral' }}">{{ $n > 0 ? $n.' '.\Illuminate\Support\Str::plural('account', $n) : 'Not connected' }}</span>
                    </span>
                    <span class="connector-tile-text">{{ $entry['blurb'] ?? $connector->description() }}</span>
                </button>
            @endforeach
        </div>
    @endforeach

    @if($definition)
        <x-cipi::modal :title="$definition->label()" close="close" size="lg" persistent :subtitle="$definition->description()" data-connector-dialog="{{ $definition->key() }}">
            <div class="modal-body space-y-5">
                @if($accounts->isNotEmpty())
                    <div class="table-scroll">
                        <table class="table-compact" data-connector-accounts>
                            <thead><tr><th>Account</th><th>Status</th><th>Checked</th><th></th></tr></thead>
                            <tbody>
                                @foreach($accounts as $acc)
                                    <tr wire:key="acc-{{ $acc->uuid }}">
                                        <td class="text-strong">{{ $acc->name }}</td>
                                        <td class="text-xs {{ $acc->isHealthy() ? 'text-soft' : 'text-danger' }} break-words">{{ $acc->status ?: '—' }}</td>
                                        <td class="text-xs text-soft">{{ $acc->checked_at?->diffForHumans() ?? '—' }}</td>
                                        <td class="text-right">
                                            <div class="btn-actions">
                                                <a href="{{ route('cipi-gui.infrastructure', ['connector' => $definition->key(), 'account' => $acc->uuid]) }}" class="btn btn-ghost btn-sm"><x-cipi::icon name="external" /> Open</a>
                                                <button type="button" wire:click="test('{{ $acc->uuid }}')" class="btn btn-ghost btn-sm" wire:loading.attr="disabled" wire:target="test"><x-cipi::icon name="play" /> Test</button>
                                                <button type="button" wire:click="edit('{{ $acc->uuid }}')" class="btn btn-ghost btn-sm"><x-cipi::icon name="pencil" /> Edit</button>
                                                <button type="button" wire:click="remove('{{ $acc->uuid }}')" class="btn btn-ghost btn-sm text-danger" data-confirm="Remove {{ $acc->name }} from the panel? Revoke its credentials at {{ $definition->label() }} too." data-confirm-danger><x-cipi::icon name="trash" /></button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($editing === null)
                        <button type="button" wire:click="edit" class="btn btn-secondary btn-sm"><x-cipi::icon name="plus" /> Add another account</button>
                    @endif
                @endif

                @if($editing !== null)
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <h3 class="text-sm text-strong mb-2">Where to make the credentials</h3>
                            <ol class="connector-steps" data-connector-steps>
                                @foreach($definition->instructions() as $step)
                                    <li>{{ $step }}</li>
                                @endforeach
                            </ol>
                            <p class="text-xs mt-3"><a href="{{ $definition->docs() }}" target="_blank" rel="noopener" class="row-link inline-flex items-center gap-1">{{ $definition->label() }} documentation <x-cipi::icon name="external" class="h-3 w-3" /></a></p>
                        </div>
                        <form wire:submit="save" class="space-y-4" novalidate data-connector-form>
                            <div>
                                <label for="conn-name">Name in the panel</label>
                                <input id="conn-name" type="text" wire:model="accountName" placeholder="{{ $definition->short() }} — production" autocomplete="off">
                                @error('accountName') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            @foreach($definition->fields() as $field)
                                @php $fid = 'conn-'.$field->name; @endphp
                                <div wire:key="cf-{{ $definition->key() }}-{{ $field->name }}">
                                    <label for="{{ $fid }}">{{ $field->label }}@unless($field->required) <span class="text-subtle font-normal">(optional)</span>@endunless</label>
                                    @if($field->type === 'textarea')
                                        <textarea id="{{ $fid }}" rows="5" wire:model="credentials.{{ $field->name }}" placeholder="{{ $field->secret && $editing !== 'new' ? 'Unchanged — paste a new one to replace it' : $field->placeholder }}" class="font-mono text-xs" autocomplete="off"></textarea>
                                    @elseif($field->type === 'select')
                                        <select id="{{ $fid }}" wire:model="credentials.{{ $field->name }}">
                                            @foreach((array) $field->options as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    @elseif($field->type === 'checkbox')
                                        <label class="inline-flex items-center gap-2 text-sm font-normal"><input id="{{ $fid }}" type="checkbox" wire:model="credentials.{{ $field->name }}"> Yes</label>
                                    @else
                                        <input id="{{ $fid }}" type="{{ $field->secret ? 'password' : 'text' }}" wire:model="credentials.{{ $field->name }}"
                                               placeholder="{{ $field->secret && $editing !== 'new' ? 'Unchanged' : $field->placeholder }}" autocomplete="{{ $field->secret ? 'new-password' : 'off' }}">
                                    @endif
                                    @if($field->help)<p class="field-hint">{{ $field->help }}</p>@endif
                                    @error('credentials.'.$field->name) <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                            @endforeach
                            @error('credentials') <x-cipi::alert type="danger">{{ $message }}</x-cipi::alert> @enderror
                            <div class="btn-group">
                                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                                    <span wire:loading.remove wire:target="save">{{ $editing === 'new' ? 'Check & add' : 'Check & save' }}</span>
                                    <span wire:loading wire:target="save" class="inline-flex items-center gap-2"><span class="spinner"></span> Checking…</span>
                                </button>
                                @if($accounts->isNotEmpty())
                                    <button type="button" wire:click="cancel" class="btn btn-secondary">Cancel</button>
                                @endif
                            </div>
                        </form>
                    </div>
                @endif
            </div>
            <div class="modal-footer">
                @if($accounts->isNotEmpty())
                    <a href="{{ route('cipi-gui.infrastructure', ['connector' => $definition->key()]) }}" class="btn btn-secondary"><x-cipi::icon name="external" /> Open {{ $definition->short() }}</a>
                @endif
                <button type="button" wire:click="close" class="btn btn-primary">Done</button>
            </div>
        </x-cipi::modal>
    @endif
</div>
