<div class="card" id="passkeys" data-passkeys
     x-data="{ busy: false, error: '', supported: true,
               init() { this.supported = !!(window.cipiPasskey && window.cipiPasskey.supported()) },
               async add() {
                   this.busy = true; this.error = '';
                   try {
                       await window.cipiPasskey.add(@js(route('cipi-gui.passkeys.register.options')), @js(route('cipi-gui.passkeys.register')), { name: $wire.newName });
                       $dispatch('notify', { type: 'success', message: 'Passkey added: next time, sign in with it alone.' });
                       $wire.$dispatch('passkeys-changed');
                   } catch (e) { this.error = window.cipiPasskey.why(e) }
                   this.busy = false;
               } }">
    <div class="card-header">
        <div>
            <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="key" /> Passkeys</h2>
            <p class="card-subtitle">Sign in with Face ID, Touch ID, Windows Hello, your phone or a security key — no password and no code: a passkey is the device and what unlocks it. It also works as the second step after the password, and it counts as two-factor authentication where an admin requires it. The private key never leaves the device; the panel keeps only the public one.</p>
        </div>
        <span class="badge {{ $passkeys->isNotEmpty() ? 'badge-green' : 'badge-neutral' }}">{{ $passkeys->isNotEmpty() ? $passkeys->count().' '.\Illuminate\Support\Str::plural('passkey', $passkeys->count()) : 'None' }}</span>
    </div>

    @if(! $enabled)
        <p class="text-sm text-soft">Passkeys are off on this panel{{ \CipiGui\Services\OidcClient::only() ? ': only single sign-on signs in.' : '.' }}</p>
    @else
        @if($passkeys->isNotEmpty())
            <div class="passkey-list mb-4">
                @foreach($passkeys as $pk)
                    <div class="passkey-row" wire:key="pk-{{ $pk->id }}" data-passkey="{{ $pk->id }}">
                        @if($renaming === $pk->id)
                            <form wire:submit="rename" class="flex flex-wrap items-center gap-2 w-full">
                                <input type="text" wire:model="renameTo" maxlength="80" class="flex-1 min-w-0" aria-label="Name" autofocus>
                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                                <button type="button" wire:click="cancelRename" class="btn btn-secondary btn-sm">Cancel</button>
                                @error('renameTo') <p class="field-error w-full">{{ $message }}</p> @enderror
                            </form>
                        @else
                            <div class="min-w-0">
                                <p class="text-sm text-strong truncate">{{ $pk->name }} @if($pk->backed_up)<span class="badge badge-blue">synced</span>@endif</p>
                                <p class="text-xs text-muted">Added {{ \CipiGui\Support\Clock::format($pk->created_at) }} · {{ $pk->last_used_at ? 'last used '.$pk->last_used_at->diffForHumans() : 'not used yet' }}</p>
                            </div>
                            <div class="btn-actions">
                                <button type="button" wire:click="startRename({{ $pk->id }})" class="btn btn-ghost btn-sm"><x-cipi::icon name="pencil" /> Rename</button>
                                <button type="button" wire:click="remove({{ $pk->id }})" class="btn btn-ghost btn-sm text-danger" data-confirm="Remove the passkey “{{ $pk->name }}”? It will no longer open this panel." data-confirm-danger><x-cipi::icon name="trash" /> Remove</button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <div class="flex flex-wrap items-start gap-2" x-show="supported">
            <input type="text" wire:model="newName" maxlength="80" placeholder="Name it: MacBook, iPhone, YubiKey…" class="flex-1 min-w-0" aria-label="Passkey name">
            <button type="button" class="btn btn-primary" x-on:click="add()" x-bind:disabled="busy" data-passkey-add>
                <x-cipi::icon name="plus" /> <span x-text="busy ? 'Waiting for the device…' : 'Add a passkey'">Add a passkey</span>
            </button>
        </div>
        <p class="field-error mt-2" x-show="error" x-text="error" x-cloak></p>
        <p class="text-sm text-soft" x-show="!supported" x-cloak>This browser has no passkeys. Open the panel in Safari, Chrome, Edge or Firefox.</p>
        <p class="field-hint mt-3">For <code>{{ $rpId }}</code>: a passkey works on this address. On another device, use your phone (a QR code) or add a passkey there too.</p>
    @endif
    @include('cipi-gui::partials.passkey-script')
</div>
