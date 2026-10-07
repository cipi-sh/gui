<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Cipi' }} — {{ config('app.name', 'Cipi') }}</title>
    @include('cipi-gui::partials.favicon')
    @include('cipi-gui::partials.theme-script')
    @include('cipi-gui::partials.fonts')
    @include('cipi-gui::partials.styles')
    @livewireStyles
</head>
<body class="cipi-gui h-full"
      x-data="{
          toasts: [],
          mobileNavOpen: false,
          notify(type, message) {
              const id = Date.now() + Math.random();
              this.toasts.push({ id, type, message });
              setTimeout(() => this.dismiss(id), type === 'error' ? 8000 : 4500);
          },
          dismiss(id) { this.toasts = this.toasts.filter(t => t.id !== id) },
      }"
      x-on:notify.window="notify($event.detail.type ?? 'info', $event.detail.message ?? '')">
    <a href="#main" class="sr-only">Skip to content</a>
    <div class="cipi-gui-shell">
        @include('cipi-gui::partials.sidebar')
        @include('cipi-gui::partials.mobile-nav')

        <div class="flex flex-1 flex-col min-w-0">
            @include('cipi-gui::partials.header')

            <main id="main" class="cipi-gui-main">
                {{ $slot }}
            </main>
        </div>
    </div>

    <div class="toast-stack" aria-live="polite">
        <template x-for="toast in toasts" :key="toast.id">
            <div class="toast" :class="'toast-' + toast.type" x-transition.opacity.duration.200ms>
                <x-cipi::icon name="check-circle" x-show="toast.type === 'success'" />
                <x-cipi::icon name="x-circle" x-show="toast.type === 'error'" />
                <x-cipi::icon name="info" x-show="toast.type !== 'success' && toast.type !== 'error'" />
                <span class="flex-1 break-words" x-text="toast.message"></span>
                <button type="button" class="toast-close" x-on:click="dismiss(toast.id)" aria-label="Dismiss">
                    <x-cipi::icon name="x" />
                </button>
            </div>
        </template>
    </div>

    @if(session('cipi_gui_flash'))
        <div x-data x-init="$nextTick(() => $dispatch('notify', @js(session('cipi_gui_flash'))))"></div>
    @endif

    @livewireScripts
    @include('cipi-gui::partials.theme-init')
</body>
</html>
