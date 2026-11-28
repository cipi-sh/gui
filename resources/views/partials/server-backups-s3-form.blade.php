{{-- An S3 bucket: a new destination, a named one being changed, or the default bucket
     ($s3Editing === 's3', `cipi backup configure`). The secret key is sent once, on the
     CLI's standard input through the API, and cleared from the form; the panel keeps
     nothing. Included by server-backups (the destinations card, and the setup card of a
     server where backups are not configured yet). --}}
@php
    $s3Default = $s3Editing === 's3';
    $s3New = $s3Editing === null;
    $s3HasDefault = (bool) collect($backup['destinations'] ?? [])->first(fn ($d) => ($d['name'] ?? '') === 's3' && ! empty($d['bucket']));
    $s3Keeps = ! $s3New && ! ($s3Default && ! $s3HasDefault);
    $s3P = \CipiGui\Support\BackupS3::PROVIDERS[$s3Provider] ?? \CipiGui\Support\BackupS3::PROVIDERS['other'];
@endphp
<form wire:submit="saveS3Destination" class="mt-4 p-4 rounded-lg bg-raised space-y-3 text-left" data-backup-s3-form="{{ $s3Default ? 'default' : ($s3New ? 'new' : 'edit') }}">
    <p class="font-semibold text-strong flex items-center gap-1.5"><x-cipi::icon name="cloud" />
        @if($s3Default)
            {{ $s3HasDefault ? 'The default bucket' : 'Backups to an S3 bucket' }}
        @elseif($s3New)
            A new S3 bucket
        @else
            S3 destination {{ $s3Editing }}
        @endif
    </p>
    @if($s3Default)
        <p class="text-sm text-soft">The bucket the nightly profile sends its archives to, as <code>s3</code> in a profile's <em>Where</em>. A copy stays on this server too.</p>
    @endif
    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
        <div class="sm:col-span-2">
            <label for="s3-provider">Provider</label>
            <select id="s3-provider" wire:model.live="s3Provider" data-backup-s3-provider>
                @foreach(\CipiGui\Support\BackupS3::PROVIDERS as $value => $p)
                    <option value="{{ $value }}">{{ $p['label'] }}</option>
                @endforeach
            </select>
            <p class="field-hint">{{ $s3P['hint'] }}</p>
        </div>
        @if($s3New)
            <div class="sm:col-span-2">
                <label for="s3-name">Name</label>
                <input id="s3-name" type="text" wire:model="s3Name" placeholder="offsite" autocomplete="off" spellcheck="false">
                <p class="field-hint">How profiles call it in <em>Where</em>.</p>
                @error('s3Name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        @endif
        <div class="sm:col-span-2">
            <label for="s3-bucket">Bucket</label>
            <input id="s3-bucket" type="text" wire:model="s3Bucket" placeholder="acme-backups" autocomplete="off" spellcheck="false">
            @error('s3Bucket') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="s3-region">Region</label>
            <input id="s3-region" type="text" wire:model="s3Region" placeholder="eu-central-1" autocomplete="off" spellcheck="false">
            @error('s3Region') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-4">
            <label for="s3-endpoint">Endpoint <span class="text-subtle font-normal">— empty for AWS</span></label>
            <input id="s3-endpoint" type="text" wire:model="s3Endpoint" placeholder="https://fsn1.your-objectstorage.com" autocomplete="off" spellcheck="false" class="font-mono">
            @error('s3Endpoint') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2">
            <label for="s3-key">Access key ID</label>
            <input id="s3-key" type="text" wire:model="s3Key" placeholder="{{ $s3Keeps ? 'unchanged — leave empty to keep it' : 'AKIA…' }}" autocomplete="off" spellcheck="false" class="font-mono">
            @error('s3Key') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2">
            <label for="s3-secret">Secret access key</label>
            <input id="s3-secret" type="password" wire:model="s3Secret" placeholder="{{ $s3Keeps ? 'unchanged — leave empty to keep it' : '' }}" autocomplete="new-password" spellcheck="false" class="font-mono" data-backup-s3-secret>
            @error('s3Secret') <p class="field-error">{{ $message }}</p> @enderror
        </div>
    </div>
    <p class="field-hint">The server reaches the bucket with these keys <strong>before</strong> it saves anything: a wrong key changes nothing. The secret goes to the server once — on its standard input, never on a command line or in a log — and the panel does not keep it. Give Cipi a key limited to this bucket.</p>
    <div class="flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="saveS3Destination" data-backup-s3-save>
            <span wire:loading.remove wire:target="saveS3Destination"><x-cipi::icon name="check" /> {{ $s3Keeps ? 'Save' : ($s3Default ? 'Reach it and save' : 'Add it') }}</span>
            <span wire:loading wire:target="saveS3Destination">Reaching the bucket…</span>
        </button>
        <button type="button" wire:click="closeS3Form" class="btn btn-ghost btn-sm">Cancel</button>
    </div>
</form>
