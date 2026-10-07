@extends('cipi-gui::layouts.guest')

@section('heading', 'Two-factor check')
@section('subtitle', 'Enter the 6-digit code from your authenticator app.')

@section('content')
    <form method="POST" action="{{ route('cipi-gui.2fa.verify') }}" class="space-y-4">
        @csrf

        <div>
            <label for="code" class="sr-only">Authentication code</label>
            <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                   placeholder="000000" required autofocus autocomplete="one-time-code" class="otp-input">
            @error('code') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn btn-primary w-full" style="min-height: 2.5rem;">Verify</button>
    </form>

    <form method="POST" action="{{ route('cipi-gui.logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="text-xs text-muted">Use another account</button>
    </form>
@endsection
