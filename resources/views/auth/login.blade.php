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
                <input type="email" id="email" name="email" value="{{ old('email') }}" required @unless($sso) autofocus @endunless autocomplete="username">
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
    @endunless
@endsection
