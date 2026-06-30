<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Cipi GUI' }} — {{ config('app.name', 'Cipi Control Panel') }}</title>
    @include('cipi-gui::partials.theme-script')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=jetbrains-mono:400,500|inter:400,500,600|inter:400,500,600,700" rel="stylesheet">
    @include('cipi-gui::partials.styles')
    @livewireStyles
</head>
<body class="cipi-gui h-full font-sans antialiased" x-data="{ toasts: [] }"
      @notify.window="toasts.push({ id: Date.now(), type: $event.detail.type, message: $event.detail.message }); setTimeout(() => toasts.shift(), 5000)">
    <div class="cipi-gui-shell flex h-full">
        @include('cipi-gui::partials.sidebar')

        <div class="flex flex-1 flex-col min-w-0">
            @include('cipi-gui::partials.header')

            <main class="cipi-gui-main flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
                {{ $slot }}
            </main>
        </div>
    </div>

    <div class="fixed bottom-4 right-4 z-50 flex flex-col gap-2 max-w-sm">
        <template x-for="toast in toasts" :key="toast.id">
            <div x-show="true" x-transition
                 :class="{
                    'toast toast-success': toast.type === 'success',
                    'toast toast-error': toast.type === 'error',
                    'toast toast-info': toast.type === 'info',
                 }"
                 x-text="toast.message">
            </div>
        </template>
    </div>

    @livewireScripts
    @include('cipi-gui::partials.theme-init')
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
