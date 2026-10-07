@props(['title', 'subtitle' => null])
<div {{ $attributes->merge(['class' => 'page-header']) }}>
    <div class="min-w-0">
        @isset($eyebrow)
            <div class="page-eyebrow">{{ $eyebrow }}</div>
        @endisset
        <h1 class="page-title">{{ $title }}</h1>
        @if($subtitle || isset($meta))
            <div class="page-subtitle">{{ $subtitle }}{{ $meta ?? '' }}</div>
        @endif
    </div>
    @isset($actions)
        <div class="page-actions">{{ $actions }}</div>
    @endisset
</div>
