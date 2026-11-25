@props(['site', 'name'])
{{-- The site's favicon (SiteIconController): fetched and cached by the panel, loaded only once the row is on screen. --}}
<img src="{{ \Illuminate\Support\Facades\URL::signedRoute('cipi-gui.site-icon', ['site' => $site, 'name' => $name], absolute: false) }}" alt="" width="20" height="20" loading="lazy" decoding="async" {{ $attributes->merge(['class' => 'site-icon']) }}>
