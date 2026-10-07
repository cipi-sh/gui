@props(['label', 'value', 'masked' => false])
<div class="secret-row" x-data="{ show: {{ $masked ? 'false' : 'true' }} }">
    <span class="secret-label">{{ $label }}</span>
    <span class="secret-value">
        @if($masked)
            <span x-show="!show">••••••••••••••••</span>
            <span x-show="show" x-cloak>{{ $value }}</span>
        @else
            {{ $value }}
        @endif
    </span>
    <span class="flex items-center gap-1">
        @if($masked)
            <button type="button" class="copy-btn" x-on:click="show = !show" :aria-label="show ? 'Hide' : 'Reveal'">
                <x-cipi::icon name="eye" x-show="!show" />
                <x-cipi::icon name="eye-off" x-show="show" x-cloak />
            </button>
        @endif
        <x-cipi::copy :value="$value" />
    </span>
</div>
