<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Sign in' }} — {{ config('app.name', 'Cipi') }}</title>
    @include('cipi-gui::partials.favicon')
    @include('cipi-gui::partials.theme-script')
    @include('cipi-gui::partials.fonts')
    @include('cipi-gui::partials.styles')
</head>
<body class="cipi-gui h-full">
    <div class="auth-shell">
        <div class="absolute top-4 right-4">
            @include('cipi-gui::partials.theme-toggle')
        </div>

        <div class="auth-card">
            <div class="flex flex-col items-center text-center mb-6">
                @include('cipi-gui::partials.logo', ['large' => true])
                <h1 class="text-2xl font-semibold mt-4">@yield('heading', 'Sign in to Cipi')</h1>
                <p class="text-muted mt-1">@yield('subtitle', 'Manage your servers, apps and deploys.')</p>
            </div>

            <div class="card p-6">
                @yield('content')
            </div>

            <p class="text-center text-xs text-subtle mt-6">
                Cipi control panel · <a href="https://cipi.sh/docs/gui" class="text-link" target="_blank" rel="noopener">Documentation</a>
            </p>
        </div>
    </div>
    @include('cipi-gui::partials.theme-init')
</body>
</html>
