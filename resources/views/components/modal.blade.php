@props(['title', 'close', 'size' => null, 'subtitle' => null])
{{-- Livewire-driven modal: render it inside @if($show…) and pass the close action (e.g. "closeCreate"). --}}
<div class="modal-overlay" wire:click.self="{{ $close }}" x-data x-on:keydown.escape.window="$wire.{{ $close }}()" role="dialog" aria-modal="true">
    <div {{ $attributes->merge(['class' => 'modal-content'.($size === 'lg' ? ' modal-lg' : '')]) }}>
        <div class="modal-header">
            <div class="min-w-0">
                <h2 class="modal-title">{{ $title }}</h2>
                @if($subtitle)
                    <p class="card-subtitle">{{ $subtitle }}</p>
                @endif
            </div>
            <button type="button" class="btn btn-ghost btn-icon btn-sm" wire:click="{{ $close }}" aria-label="Close">
                <x-cipi::icon name="x" />
            </button>
        </div>
        {{ $slot }}
    </div>
</div>
