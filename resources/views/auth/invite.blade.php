@extends('cipi-gui::layouts.guest')

@section('heading', $usable ? 'Join this Cipi panel' : 'Invite link not valid')
@section('subtitle', $usable ? 'Create your account'.($invite->label ? ' — '.$invite->label : '').'.' : 'Ask an admin of the panel for a new link.')

@section('content')
    @if($usable)
        <form method="POST" action="{{ route('cipi-gui.invite.accept', ['token' => $token]) }}" class="space-y-4">
            @csrf

            <p class="text-sm text-soft">
                You join as <strong class="text-strong">{{ $invite->role === 'admin' ? 'an admin' : 'a user' }}</strong>
                @if($invite->role !== 'admin') with the servers and apps the admin picked for you @endif.
                The link works once and expires {{ $invite->expires_at->diffForHumans() }}.
            </p>

            <div>
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="username">
                @error('email') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="new-password">
                <p class="field-hint">At least 12 characters with upper and lower case, a number and a symbol.</p>
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password_confirmation">Confirm password</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn-primary w-full" style="min-height: 2.5rem;">Create account</button>
        </form>
    @else
        <p class="text-sm text-soft">
            @if($invite && $invite->accepted_at)
                This invite was already used to create an account.
            @elseif($invite)
                This invite expired {{ $invite->expires_at->diffForHumans() }}.
            @else
                This link does not match any invite.
            @endif
        </p>
        <a href="{{ route('cipi-gui.login') }}" class="btn btn-secondary w-full mt-4">Sign in</a>
    @endif
@endsection
