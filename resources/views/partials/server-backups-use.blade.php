{{-- A new destination — an S3 bucket or an SFTP server: the profiles that send their runs
     there too (`--use`). Ticked first: those that keep their runs on this server only. --}}
@php $useProfiles = array_values(array_filter((array) ($backup['profiles'] ?? []), fn ($p) => is_array($p) && isset($p['name']))); @endphp
@if($useProfiles !== [])
    <div data-backup-dest-use>
        <span class="label">Send these profiles there too</span>
        <div class="flex flex-wrap gap-2">
            @foreach($useProfiles as $p)
                <label class="check-card" wire:key="dest-use-{{ $p['name'] }}"><input type="checkbox" wire:model="destUse" value="{{ $p['name'] }}"><span><span class="check-card-title">{{ $p['name'] }}</span><span class="check-card-text">now: {{ implode(', ', (array) ($p['destinations'] ?? [])) ?: '—' }}</span></span></label>
            @endforeach
        </div>
        <p class="field-hint">Ticked: the profiles that keep their runs on this server only. Any profile can be pointed here later, in its <em>Where</em>.</p>
    </div>
@endif
