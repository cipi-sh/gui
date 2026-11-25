<?php

namespace CipiGui\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The icon of an app's site, shown in the apps list. The panel fetches it
 * once — the first <link rel="icon"> of the home page, then
 * apple-touch-icon, then /favicon.ico — and keeps it a week (a site without
 * one: a day, with the app's initial in its place); the browser keeps it as long. The
 * URL is signed by the page that shows it, so the route needs no session and
 * fetches only sites the panel itself listed.
 */
class SiteIconController
{
    private const MAX_BYTES = 262144;

    public function __invoke(Request $request): Response
    {
        $site = (string) $request->query('site');
        abort_unless(preg_match('#^https?://[^/?\#]+$#i', $site) === 1, 404);

        $key = 'cipi-gui.site-icon.'.sha1($site);
        $icon = Cache::get($key);

        if (! is_array($icon)) {
            $fetched = self::fetch($site);
            // The bytes as base64: a database cache keeps text, and MySQL refuses bytes that are not UTF-8.
            $icon = $fetched ? [$fetched[0], base64_encode($fetched[1])] : [];
            Cache::put($key, $icon, $icon ? now()->addWeek() : now()->addDay());
        }

        [$type, $body] = $icon
            ? [$icon[0], base64_decode($icon[1])]
            : ['image/svg+xml', self::placeholder((string) $request->query('name'))];

        return response($body, 200, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, max-age='.($icon ? 604800 : 86400),
            // A site's SVG is served from the panel's origin: never let it run anything if opened directly.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{0: string, 1: string}|null [content type, bytes] */
    public static function fetch(string $site): ?array
    {
        // One request of the panel may wait this long, never until PHP's max_execution_time.
        $deadline = microtime(true) + 10;
        $http = Http::connectTimeout(3)->timeout(5)->withUserAgent('Mozilla/5.0 (compatible; CipiGUI)')
            ->withOptions(['allow_redirects' => [
                'max' => 3,
                'protocols' => ['http', 'https'],
                'on_redirect' => function ($request, $response, $uri): void {
                    if (! self::isPublic((string) $uri)) {
                        throw new \RuntimeException('Redirect to a private address');
                    }
                },
            ]]);

        if (! self::isPublic($site)) {
            return null;
        }

        try {
            $page = $http->get($site);
        } catch (\Throwable) {
            return null; // Down or unknown: /favicon.ico would only wait as long again.
        }

        $icons = $touch = [];
        if ($page->successful()) {
            $base = (string) ($page->effectiveUri() ?? $site);
            preg_match_all('/<link\b[^>]*>/i', $page->body(), $tags);
            foreach ($tags[0] as $tag) {
                if (! preg_match('/\srel\s*=\s*["\']?([^"\'>]+)/i', $tag, $rel)
                    || ! preg_match('/\shref\s*=\s*["\']?([^"\'\s>]+)/i', $tag, $href)
                    || ! ($url = self::resolve(html_entity_decode($href[1]), $base))) {
                    continue;
                }
                if (preg_match('/(^|\s)icon(\s|$)/i', $rel[1])) {
                    $icons[] = $url;
                } elseif (preg_match('/(^|\s)apple-touch-icon(-precomposed)?(\s|$)/i', $rel[1])) {
                    $touch[] = $url;
                }
            }
        }

        $urls = [...array_slice(array_unique([...$icons, ...$touch]), 0, 3), self::resolve('/favicon.ico', $site)];
        foreach (array_unique($urls) as $url) {
            if (microtime(true) > $deadline) {
                break;
            }
            if ($url === null || ! self::isPublic($url)) {
                continue;
            }

            try {
                $res = $http->get($url);
            } catch (\Throwable) {
                continue;
            }

            $body = $res->body();
            // Laravel ships an empty public/favicon.ico: that is no icon.
            if (! $res->successful() || $body === '' || strlen($body) > self::MAX_BYTES) {
                continue;
            }

            $type = strtolower(trim((string) strtok((string) $res->header('Content-Type'), ';')));
            if (! str_starts_with($type, 'image/')) {
                $type = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
            }
            if (str_starts_with($type, 'image/')) {
                return [$type, $body];
            }
        }

        return null;
    }

    /** An href of the page as an absolute http(s) URL; null for data: and the like. */
    public static function resolve(string $href, string $base): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        $parts = parse_url($base);
        if (! isset($parts['scheme'], $parts['host']) || preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return $parts['scheme'].':'.$href;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $dir = preg_replace('#[^/]*$#', '', $parts['path'] ?? '') ?: '/';

        return $origin.(str_starts_with($href, '/') ? $href : $dir.$href);
    }

    /**
     * False when the URL's host is this machine or a private network: a site (or a
     * redirect, or a <link>) must not make the panel probe its own network.
     * ponytail: IPv4 lookup only and no pinning against DNS rebinding; pin the IP with CURLOPT_RESOLVE if that matters.
     */
    private static function isPublic(string $url): bool
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        // A host that does not resolve is not refused here: the request fails on its own.
        return filter_var($ip, FILTER_VALIDATE_IP) === false
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** The first letter of the app's name, on a grey that reads on both themes. */
    private static function placeholder(string $name): string
    {
        $letter = htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1)) ?: '?');

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            .'<rect width="32" height="32" rx="7" fill="#8b919e" fill-opacity=".2"/>'
            .'<text x="16" y="21.5" text-anchor="middle" font-family="system-ui,-apple-system,sans-serif" font-size="16" font-weight="600" fill="#8b919e">'.$letter.'</text>'
            .'</svg>';
    }
}
