@props(['value', 'label' => 'Copy'])
<button type="button" {{ $attributes->merge(['class' => 'copy-btn']) }}
        x-data="{ done: false }"
        x-on:click="window.cipiCopy({{ \Illuminate\Support\Js::from((string) $value) }}).then(() => { done = true; setTimeout(() => done = false, 1600) })"
        :title="done ? 'Copied' : @js($label)">
    <x-cipi::icon name="copy" x-show="!done" />
    <x-cipi::icon name="check" x-show="done" x-cloak />
    <span x-text="done ? 'Copied' : @js($label)">{{ $label }}</span>
</button>
