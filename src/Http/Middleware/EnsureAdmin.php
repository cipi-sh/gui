<?php

namespace CipiGui\Http\Middleware;

use Closure;
use CipiGui\Services\Access;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Access::isAdmin($request->user()), 403, 'Only admins can open this page.');

        return $next($request);
    }
}
