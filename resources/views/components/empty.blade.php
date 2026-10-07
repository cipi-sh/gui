@props(['icon' => 'info', 'title'])
<div {{ $attributes->merge(['class' => 'empty-state']) }}>
    <div class="empty-state-icon"><x-cipi::icon :name="$icon" /></div>
    <h3>{{ $title }}</h3>
    @if(trim($slot) !== '')
        <p>{{ $slot }}</p>
    @endif
    @isset($actions)
        <div class="btn-group justify-center mt-3">{{ $actions }}</div>
    @endisset
</div>
