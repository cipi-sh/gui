@props(['name', 'stroke' => '1.5'])
@php
    $hasSize = (bool) preg_match('/(^|\s)(h|w)-/', (string) $attributes->get('class', ''));
@endphp
<svg {{ $attributes->class([$hasSize ? '' : 'h-4 w-4']) }} fill="none" viewBox="0 0 24 24" stroke-width="{{ $stroke }}" stroke="currentColor" aria-hidden="true">@foreach(\CipiGui\Support\Icons::paths($name) as $d)<path stroke-linecap="round" stroke-linejoin="round" d="{{ $d }}" />@endforeach</svg>
