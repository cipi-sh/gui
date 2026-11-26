@extends('cipi-gui::layouts.guest')

@section('content')
    @if($sso)
        <a href="{{ route('cipi-gui.sso.redirect') }}" class="btn btn-primary w-full" style="min-height: 2.5rem;">Sign in with {{ $ssoLabel }}</a>

        @if($ssoOnly)
            @error('email') <p class="field-error mt-3">{{ $message }}</p> @enderror
            <p class="text-xs text-muted text-center mt-4">Password sign-in is off on this panel.</p>
        @else
            <div class="flex items-center gap-3 text-xs text-subtle mt-4 mb-4"><hr class="flex-1"> or <hr class="flex-1"></div>
        @endif
    @endif

    @unless($ssoOnly)
        <form method="POST" action="{{ route('cipi-gui.login.submit') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required @unless($sso) autofocus @endunless autocomplete="{{ ($passkeys ?? false) ? 'username webauthn' : 'username' }}">
                @error('email') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <label class="check text-sm">
                <input type="checkbox" id="remember" name="remember" value="1"> Keep me signed in
            </label>

            <button type="submit" class="btn btn-primary w-full" style="min-height: 2.5rem;">Sign in</button>
        </form>

        @if($passkeys ?? false)
            <div data-passkey-login hidden>
                <div class="auth-divider">or</div>
                <button type="button" class="btn btn-secondary w-full" style="min-height: 2.5rem;" data-passkey-button>
                    <x-cipi::icon name="key" /> Sign in with a passkey
                </button>
                <p class="field-error mt-2" data-passkey-error hidden></p>
                <p class="text-xs text-muted text-center mt-3">Face ID, Touch ID, Windows Hello, your phone or a security key — no password, no code.</p>
            </div>
            @include('cipi-gui::partials.passkey-script')
            <script>
                (() => {
                    const box = document.querySelector('[data-passkey-login]');
                    if (!box || !window.cipiPasskey.supported()) return;
                    box.hidden = false;
                    const btn = box.querySelector('[data-passkey-button]');
                    const err = box.querySelector('[data-passkey-error]');
                    const show = (m) => { err.textContent = m; err.hidden = false; btn.disabled = false; };
                    const remember = () => ({ remember: document.getElementById('remember')?.checked ? 1 : 0 });
                    btn.addEventListener('click', async () => {
                        err.hidden = true; btn.disabled = true;
                        try { await window.cipiPasskey.use(@js(route('cipi-gui.passkeys.login.options')), @js(route('cipi-gui.passkeys.login')), remember()); }
                        catch (e) { show(window.cipiPasskey.why(e)); }
                    });
                    window.cipiPasskey.autofill(@js(route('cipi-gui.passkeys.login.options')), @js(route('cipi-gui.passkeys.login')), show);
                })();
            </script>
        @endif
    @endunless
@endsection
