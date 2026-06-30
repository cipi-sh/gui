<?php

namespace CipiGui\Http\Controllers;

use CipiGui\Support\Theme;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssetsController
{
    public function favicon(): BinaryFileResponse|Response
    {
        $path = Theme::faviconPath();

        if (! is_readable($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
