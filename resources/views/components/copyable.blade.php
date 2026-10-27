@props(['value', 'label' => 'Copy'])
{{-- Inline value (an IP, a host) that copies itself on click; the icon shows on hover and turns into a check once copied. --}}
<button type="button" {{ $attributes->merge(['class' => 'copyable']) }}
        x-data="{ done: false }"
        x-on:click="window.cipiCopy({{ \Illuminate\Support\Js::from((string) $value) }}).then(() => { done = true; setTimeout(() => done = false, 1600) })"
        :title="done ? 'Copied' : @js($label)" :aria-label="done ? 'Copied' : @js($label.': '.$value)">
    <span class="copyable-text">{{ trim($slot) !== '' ? $slot : $value }}</span>
    <x-cipi::icon name="copy" x-show="!done" />
    <x-cipi::icon name="check" x-show="done" x-cloak />
</button>
