{{-- An SFTP server: a new destination, one being changed, or the first setup of a server
     where backups are not configured yet ($sftpSetup: `cipi backup configure --type=sftp`).
     No password: Cipi makes a key for it and shows it once saved. Included by server-backups. --}}
<form wire:submit="saveSftpDestination" class="mt-4 p-4 rounded-lg bg-raised space-y-3 text-left" data-backup-sftp-form="{{ $sftpSetup ? 'setup' : ($sftpEditing ? 'edit' : 'new') }}">
    <div class="flex items-center justify-between gap-2">
        <p class="font-semibold text-strong flex items-center gap-1.5"><x-cipi::icon name="server-stack" /> {{ $sftpSetup ? 'Backups to an SFTP server' : ($sftpEditing ? 'SFTP destination '.$sftpEditing : 'A new SFTP destination') }}</p>
        @unless($sftpEditing)
            <button type="button" wire:click="sftpPresetStorageBox" class="btn btn-ghost btn-xs" data-backup-sftp-storagebox>Hetzner Storage Box</button>
        @endunless
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
        <div>
            <label for="sftp-name">Name</label>
            <input id="sftp-name" type="text" wire:model="sftpName" placeholder="box" @disabled($sftpEditing) autocomplete="off" spellcheck="false">
            @error('sftpName') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2">
            <label for="sftp-host">Host</label>
            <input id="sftp-host" type="text" wire:model="sftpHost" placeholder="u1234.your-storagebox.de" autocomplete="off" spellcheck="false">
            @error('sftpHost') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="sftp-port">Port</label>
            <input id="sftp-port" type="text" inputmode="numeric" wire:model="sftpPort" placeholder="22">
            @error('sftpPort') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="sftp-user">User</label>
            <input id="sftp-user" type="text" wire:model="sftpUser" placeholder="u1234" autocomplete="off" spellcheck="false">
            @error('sftpUser') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-3">
            <label for="sftp-path">Folder on the remote</label>
            <input id="sftp-path" type="text" wire:model="sftpPath" placeholder="cipi (empty: the login's home)" autocomplete="off" spellcheck="false">
            @error('sftpPath') <p class="field-error">{{ $message }}</p> @enderror
        </div>
    </div>
    @if($sftpSetup)
        <p class="text-sm text-soft">The nightly profile sends its archives there, as <code>{{ $sftpName !== '' ? $sftpName : 'sftp' }}</code> in a profile's <em>Where</em>. Cipi then shows the key the server has to accept.</p>
    @endif
    <p class="field-hint">Cipi signs in with a key it makes for this destination — never with a password. Only SFTP is asked of the remote: a chrooted <code>internal-sftp</code> account works. Runs go to <code>&lt;folder&gt;/cipi/&lt;profile&gt;/&lt;run&gt;/</code>.</p>
    <div class="flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="saveSftpDestination" data-backup-sftp-save><x-cipi::icon name="check" /> {{ $sftpSetup ? 'Set it up' : ($sftpEditing ? 'Save' : 'Add it') }}</button>
        @if($sftpEditing)
            <button type="button" wire:click="saveSftpDestination(true)" data-confirm="Make a new key for {{ $sftpEditing }}? The old one stops working here: install the new one on the remote." class="btn btn-secondary btn-sm"><x-cipi::icon name="key" /> Save with a new key</button>
        @endif
        <button type="button" wire:click="closeSftpForm" class="btn btn-ghost btn-sm">Cancel</button>
    </div>
</form>
