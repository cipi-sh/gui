<?php

namespace CipiGui\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->two_factor_enabled && ! $request->session()->get('cipi_gui_2fa_verified')) {
            if ($request->routeIs('cipi-gui.2fa*')) {
                return $next($request);
            }

            return redirect()->route('cipi-gui.2fa');
        }

        return $next($request);
    }
}
