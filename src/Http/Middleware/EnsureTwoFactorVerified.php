<?php

namespace CipiGui\Http\Middleware;

use Closure;
use CipiGui\Services\TwoFactorService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every page of the panel but the 2FA ones:
 *  - an account with 2FA gives its code once per session (/2fa);
 *  - when an admin requires 2FA for everyone (4.6.0), an account without it
 *    sets it up first (/2fa/setup) — at sign-in, and at the next request of
 *    a session that was open when it was turned on. SSO sessions are left to
 *    the identity provider's MFA.
 *
 * Also a persistent Livewire middleware (CipiGuiServiceProvider): an action
 * from a page that was open before the requirement is refused the same way.
 * Livewire runs it on a request of its own making, which may carry neither
 * the user nor the session: both are taken from the application then.
 */
class EnsureTwoFactorVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? auth()->user();

        if (! $user || $request->routeIs('cipi-gui.2fa*')) {
            return $next($request);
        }

        $session = $request->hasSession() ? $request->session() : app('session.store');

        if (($user->two_factor_enabled ?? false) && ! $session->get('cipi_gui_2fa_verified')) {
            return redirect()->route('cipi-gui.2fa');
        }

        if (TwoFactorService::setupRequired($user, $session)) {
            // A page asked for is opened once the setup is done; an action is not
            // repeated. Livewire's own request is the application's, not $request.
            $action = ! $request->isMethod('GET') || $request->hasHeader('X-Livewire') || request()->hasHeader('X-Livewire');

            return $action ? redirect()->route('cipi-gui.2fa.setup') : redirect()->guest(route('cipi-gui.2fa.setup'));
        }

        return $next($request);
    }
}
