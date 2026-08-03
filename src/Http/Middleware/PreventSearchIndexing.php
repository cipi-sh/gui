<?php

namespace CipiGui\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventSearchIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
