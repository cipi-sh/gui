<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Sign in' }} — Cipi GUI</title>
    @include('cipi-gui::partials.favicon')
    @include('cipi-gui::partials.theme-script')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600" rel="stylesheet">
    @include('cipi-gui::partials.styles')
</head>
<body class="cipi-gui h-full font-sans antialiased">
    <div class="flex min-h-full flex-col justify-center py-12 sm:px-6 lg:px-8 relative">
        <div class="absolute top-4 right-4 sm:top-6 sm:right-6">
            @include('cipi-gui::partials.theme-toggle')
        </div>

        <div class="sm:mx-auto sm:w-full sm:max-w-md">
            <div class="flex justify-center mb-6">
                @include('cipi-gui::partials.logo', ['large' => true])
            </div>
            <h2 class="text-center text-xl font-semibold tracking-tight">Cipi</h2>
            <p class="mt-1 text-center text-sm text-surface-400">@yield('subtitle', 'Sign in to manage your servers')</p>
        </div>

        <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
            <div class="card">
                @yield('content')
            </div>
        </div>
    </div>
    @include('cipi-gui::partials.theme-init')
</body>
</html>
