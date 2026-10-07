@props(['type' => 'info', 'title' => null])
@php
    $icon = match ($type) {
        'success' => 'check-circle',
        'warn', 'warning' => 'warning',
        'danger', 'error' => 'x-circle',
        default => 'info',
    };
    $variant = match ($type) {
        'warning' => 'warn',
        'error' => 'danger',
        default => $type,
    };
@endphp
<div {{ $attributes->merge(['class' => 'alert alert-'.$variant]) }} role="{{ in_array($variant, ['danger', 'warn'], true) ? 'alert' : 'status' }}">
    <x-cipi::icon :name="$icon" class="shrink-0" />
    <div class="min-w-0 flex-1">
        @if($title)
            <p class="alert-title">{{ $title }}</p>
        @endif
        <div>{{ $slot }}</div>
    </div>
</div>
