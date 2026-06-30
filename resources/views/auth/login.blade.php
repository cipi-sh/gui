@extends('cipi-gui::layouts.guest')

@section('content')
    <form method="POST" action="{{ route('cipi-gui.login.submit') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
            @error('email')
                <p class="text-sm text-red-400 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
            @error('password')
                <p class="text-sm text-red-400 mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex items-center gap-2">
            <input type="checkbox" id="remember" name="remember" value="1">
            <label for="remember" style="margin:0;font-weight:400;color:var(--color-surface-400);">Remember me</label>
        </div>

        <button type="submit" class="btn btn-primary w-full py-3">Sign in</button>
    </form>
@endsection
