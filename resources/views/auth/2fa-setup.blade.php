@extends('cipi-gui::layouts.guest')

@section('heading', 'Set up two-factor authentication')
@section('subtitle', 'An admin of this panel requires it for every account. It takes a minute, once.')

@section('content')
    <div class="space-y-4" data-2fa-setup>
        <ol class="space-y-2 text-sm text-soft" style="list-style: decimal; padding-left: 1.1rem;">
            <li>Open an authenticator app on your phone — 1Password, Aegis, Google Authenticator, Microsoft Authenticator…</li>
            <li>Scan this QR code, or type the setup key.</li>
            <li>Enter the 6-digit code the app shows.</li>
        </ol>

        <div class="qr-box">{!! $qrCodeSvg !!}</div>

        <div>
            <p class="text-xs text-muted">Setup key for <span class="text-strong">{{ $email }}</span></p>
            <p class="font-mono text-sm text-strong break-all select-all" data-2fa-secret>{{ trim(chunk_split($secret, 4, ' ')) }}</p>
        </div>

        <form method="POST" action="{{ route('cipi-gui.2fa.enable') }}" class="space-y-4">
            @csrf
            <div>
                <label for="code">Verification code</label>
                <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                       placeholder="000000" required autofocus autocomplete="one-time-code" class="otp-input">
                @error('code') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary w-full" style="min-height: 2.5rem;">Turn on 2FA and continue</button>
        </form>

        <p class="text-xs text-muted">From now on every sign-in asks for a code from this app. Lost the phone? An admin can reset your 2FA from Team, or on the server: <code>cipi gui reset-user</code>.</p>
        @if($sso)
            <p class="text-xs text-muted">Signing in with {{ $ssoLabel }} instead? Its own MFA counts: sign out and use “Sign in with {{ $ssoLabel }}”.</p>
        @endif
    </div>

    <form method="POST" action="{{ route('cipi-gui.logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="text-xs text-muted">Sign out</button>
    </form>
@endsection
