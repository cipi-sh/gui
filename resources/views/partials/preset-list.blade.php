{{-- The one-click commands above the Artisan and Commands inputs.
     $kind: artisan | run · $presets: the list · $use: the action that fills the input
     · $canEdit: the user is an admin of the panel · $editing: this list is in edit mode
     · $custom: the list was changed from the defaults. --}}
<div class="mb-4" data-presets="{{ $kind }}">
    <div class="flex flex-wrap items-center gap-1.5">
        @forelse($presets as $preset)
            @if($editing)
                <span class="preset-chip" wire:key="preset-{{ $kind }}-edit-{{ md5($preset) }}">
                    <span class="font-mono">{{ $preset }}</span>
                    <button type="button" wire:click="removePreset('{{ $kind }}', @js($preset))" class="preset-chip-remove" title="Remove {{ $preset }} from the list" aria-label="Remove {{ $preset }} from the list"><x-cipi::icon name="x" /></button>
                </span>
            @else
                <button type="button" wire:click="{{ $use }}(@js($preset))" wire:key="preset-{{ $kind }}-{{ md5($preset) }}" class="btn btn-secondary btn-xs font-mono">{{ $preset }}</button>
            @endif
        @empty
            <span class="text-xs text-muted">No saved commands.@if($canEdit && ! $editing) Add the ones your team runs.@endif</span>
        @endforelse
        @if($canEdit)
            <button type="button" wire:click="editPresets('{{ $kind }}')" class="btn btn-ghost btn-xs" data-presets-edit>
                @if($editing)<x-cipi::icon name="check" /> Done @else<x-cipi::icon name="pencil" /> Edit list @endif
            </button>
        @endif
    </div>
    @if($editing)
        <div class="preset-editor">
            <form wire:submit="addPreset('{{ $kind }}')" class="input-group">
                @if($kind === 'artisan')<span class="input-addon">php artisan</span>@else<span class="input-addon">$</span>@endif
                <input type="text" wire:model="presetDraft" class="font-mono flex-1" maxlength="{{ \CipiGui\Support\Presets::MAX_LENGTH }}" placeholder="{{ $kind === 'artisan' ? 'horizon:terminate' : 'composer show --outdated' }}" autocomplete="off" aria-label="Command to save">
                <button type="submit" class="btn btn-secondary"><x-cipi::icon name="plus" /> Save command</button>
            </form>
            @error('presetDraft') <p class="field-error">{{ $message }}</p> @enderror
            <p class="field-hint">
                One list for every app and everyone on the panel. Leave the box empty to save what is in the input below.
                @if($custom)
                    <button type="button" wire:click="resetPresets('{{ $kind }}')" data-confirm="Restore the default list? The commands you added are removed and the ones you removed come back." data-confirm-label="Restore defaults" class="text-link">Restore the defaults</button>
                @endif
            </p>
        </div>
    @endif
</div>
