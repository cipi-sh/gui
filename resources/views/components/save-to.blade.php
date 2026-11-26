@props(['logins' => [], 'name' => 'cipi'])
{{-- "Save to…": the logins shown here, as the file each password manager imports
     (CipiGui\Support\PasswordManagers). Built with the page, downloaded from the
     browser's memory: nothing is stored on the panel, nothing is sent anywhere. --}}
@php
    $saveLogins = array_values(array_filter(array_map([\CipiGui\Support\PasswordManagers::class, 'login'], array_filter((array) $logins, 'is_array'))));
    $saveMenu = $saveLogins === [] ? [] : \CipiGui\Support\PasswordManagers::menu($saveLogins, (string) $name);
    $saveFiles = [];
    foreach ($saveMenu as $saveItem) {
        $saveFiles[$saveItem['id']] = ['filename' => $saveItem['filename'], 'content' => $saveItem['content'], 'hint' => $saveItem['hint']];
    }
@endphp
@if($saveMenu !== [])
    <div {{ $attributes->merge(['class' => 'save-to']) }} data-save-to
         x-data="{ open: false, saved: null, files: {{ \Illuminate\Support\Js::from($saveFiles) }}, text: {{ \Illuminate\Support\Js::from(\CipiGui\Support\PasswordManagers::text($saveLogins)) }} }"
         x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
        <div class="dropdown">
            <button type="button" class="btn btn-secondary btn-sm" x-on:click="open = !open" :aria-expanded="open" aria-haspopup="menu">
                <x-cipi::icon name="download" /> Save to… <x-cipi::icon name="chevron-down" class="h-3 w-3" />
            </button>
            <div class="dropdown-menu left" x-show="open" x-cloak x-transition.opacity.duration.150ms role="menu">
                <p class="dropdown-label">Your password manager — as the file it imports</p>
                @foreach($saveMenu as $saveItem)
                    <button type="button" class="dropdown-item" role="menuitem" data-save-to-provider="{{ $saveItem['id'] }}"
                            x-on:click="window.cipiDownload(files['{{ $saveItem['id'] }}'].filename, files['{{ $saveItem['id'] }}'].content); saved = '{{ $saveItem['id'] }}'; open = false">
                        <x-cipi::icon name="key" /> {{ $saveItem['label'] }}
                    </button>
                @endforeach
                <div class="dropdown-divider"></div>
                <button type="button" class="dropdown-item" role="menuitem" x-on:click="window.cipiCopy(text); open = false"><x-cipi::icon name="copy" /> Copy everything as text</button>
            </div>
        </div>
        <p class="field-hint" x-show="saved" x-cloak data-save-to-hint>
            <span x-text="saved ? files[saved].hint : ''"></span>
            <strong>Then delete the downloaded file</strong> — it holds the passwords in clear.
        </p>
    </div>
@endif
