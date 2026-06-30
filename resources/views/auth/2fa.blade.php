@extends('cipi-gui::layouts.guest')

@section('subtitle', 'Enter your authentication code')

@section('content')
    <form method="POST" action="{{ route('cipi-gui.2fa.verify') }}" class="space-y-4">
        @csrf

        <div>
            <label for="code">Authentication code</label>
            <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                   placeholder="000000" required autofocus autocomplete="one-time-code"
                   style="text-align:center;font-size:1.5rem;letter-spacing:0.3em;font-family:'JetBrains Mono',monospace;">
            @error('code')
                <p class="text-sm text-red-400 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <p class="text-xs text-surface-500">Enter the 6-digit code from your authenticator app.</p>

        <button type="submit" class="btn btn-primary w-full py-3">Verify</button>
    </form>
@endsection
