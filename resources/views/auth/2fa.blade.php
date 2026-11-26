@extends('cipi-gui::layouts.guest')

@section('heading', 'Two-factor check')
@section('subtitle', $totp ? ($passkey ? 'Enter the 6-digit code from your authenticator app, or use a passkey.' : 'Enter the 6-digit code from your authenticator app.') : 'Use your passkey to finish signing in.')

@section('content')
    @if($totp)
        <form method="POST" action="{{ route('cipi-gui.2fa.verify') }}" class="space-y-4">
            @csrf

            <div>
                <label for="code" class="sr-only">Authentication code</label>
                <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                       placeholder="000000" required @unless($passkey) autofocus @endunless autocomplete="one-time-code" class="otp-input">
                @error('code') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary w-full" style="min-height: 2.5rem;">Verify</button>
        </form>
    @endif

    @if($passkey)
        <div data-passkey-2fa>
            @if($totp)<div class="auth-divider">or</div>@endif
            <button type="button" class="btn {{ $totp ? 'btn-secondary' : 'btn-primary' }} w-full" style="min-height: 2.5rem;" data-passkey-button>
                <x-cipi::icon name="key" /> Use a passkey
            </button>
            <p class="field-error mt-2" data-passkey-error hidden></p>
        </div>
        @include('cipi-gui::partials.passkey-script')
        <script>
            (() => {
                const box = document.querySelector('[data-passkey-2fa]');
                const btn = box.querySelector('[data-passkey-button]');
                const err = box.querySelector('[data-passkey-error]');
                if (!window.cipiPasskey.supported()) { err.textContent = 'This browser has no passkeys: use the code from your authenticator app.'; err.hidden = false; btn.disabled = true; return; }
                btn.addEventListener('click', async () => {
                    err.hidden = true; btn.disabled = true;
                    try { await window.cipiPasskey.use(@js(route('cipi-gui.2fa.passkey.options')), @js(route('cipi-gui.2fa.passkey'))); }
                    catch (e) { err.textContent = window.cipiPasskey.why(e); err.hidden = false; btn.disabled = false; }
                });
            })();
        </script>
    @endif

    <form method="POST" action="{{ route('cipi-gui.logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="text-xs text-muted">Use another account</button>
    </form>
@endsection
