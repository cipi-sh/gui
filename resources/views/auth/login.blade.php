@extends('cipi-gui::layouts.guest')

@section('content')
    <form method="POST" action="{{ route('cipi-gui.login.submit') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
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
@endsection
