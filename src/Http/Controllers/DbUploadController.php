<?php

namespace CipiGui\Http\Controllers;

use CipiGui\Models\CipiServer;
use CipiGui\Services\Access;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A database dump on its way from the browser to a server (API 1.39+ / Cipi
 * ≥ 5.9.0), for the Restore dialog of the Databases page.
 *
 * The browser cuts the file into chunks and posts them here one at a time;
 * each is handed on to the server's API, which appends it to the upload. The
 * panel keeps nothing: a chunk is a few megabytes in memory for the length
 * of one request, so a dump of any size passes through request limits that
 * were never meant for it (64 MB on a stock panel, 2 MB on a stock PHP).
 * When the last chunk is in, the page restores the database from the upload.
 *
 * Every request names its server and is checked there: the signed-in user
 * must manage it — the level that restores a database.
 */
class DbUploadController
{
    /** Start an upload: the server's limits decide, and what it answers comes back with the chunk size to use. */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'server' => ['required', 'integer'],
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
        ]);

        return $this->forward($request, (int) $validated['server'], function (CipiApiClient $api) use ($validated) {
            $data = $api->startDbUpload($validated['filename'], (int) $validated['size']);
            if (! isset($data['upload_id'], $data['chunk_size'])) {
                throw new CipiApiException('The server did not start the upload.', 502);
            }
            $data['chunk_size'] = max(1, min((int) $data['chunk_size'], self::panelChunkBytes()));

            return $data;
        });
    }

    /** Hand one chunk on. `index` starts at 0; the server refuses one that is out of order. */
    public function chunk(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'server' => ['required', 'integer'],
            'index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file'],
        ]);
        $file = $request->file('chunk');
        if (! $file || ! $file->isValid()) {
            return response()->json(['error' => 'The chunk did not arrive whole. The panel may accept less per request than the chunk size.'], 422);
        }
        $contents = (string) $file->get();

        return $this->forward($request, (int) $validated['server'], fn (CipiApiClient $api) => $api->sendDbUploadChunk($id, (int) $validated['index'], $contents));
    }

    /** Give up: the server deletes what arrived. */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['server' => ['required', 'integer']]);

        return $this->forward($request, (int) $validated['server'], function (CipiApiClient $api) use ($id) {
            $api->cancelDbUpload($id);

            return ['upload_id' => $id, 'cancelled' => true];
        });
    }

    /**
     * How much of a dump one request to the panel may carry: the configured
     * chunk size, and never more than PHP lets a request upload here.
     */
    public static function panelChunkBytes(): int
    {
        $limit = max(1, (int) config('cipi-gui.db_upload_chunk_mb', 4)) * 1048576;
        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $bytes = self::iniBytes((string) ini_get($directive));
            if ($bytes > 0) {
                // Room for the form fields and the multipart envelope around the chunk.
                $limit = min($limit, max(65536, (int) floor($bytes * 0.9) - 4096));
            }
        }

        return $limit;
    }

    /** 2147483648 → "2 GB", 524288000 → "500 MB". */
    public static function humanBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => rtrim(rtrim(number_format($bytes / 1073741824, 1, '.', ''), '0'), '.').' GB',
            $bytes >= 1048576 => (string) round($bytes / 1048576).' MB',
            $bytes >= 1024 => (string) round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }

    /** "64M", "2G", "8388608" → bytes; 0 for unlimited or unreadable. */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/^(\d+)\s*([kmg])?b?$/i', $value, $m)) {
            return 0;
        }

        return (int) $m[1] * match (strtolower($m[2] ?? '')) {
            'k' => 1024,
            'm' => 1048576,
            'g' => 1073741824,
            default => 1,
        };
    }

    /**
     * @param  callable(CipiApiClient): array<string, mixed>  $call
     */
    protected function forward(Request $request, int $serverId, callable $call): JsonResponse
    {
        $server = CipiServer::where('is_active', true)->find($serverId);
        if (! $server || ! Access::canManageServer($request->user(), $server->id)) {
            return response()->json(['error' => 'Your access does not include restoring databases on this server.'], 403);
        }

        try {
            return response()->json(['data' => $call(CipiApiClient::for($server))]);
        } catch (CipiApiException $e) {
            $status = $e->getStatusCode();

            return response()->json(['error' => $e->getMessage()], $status >= 400 && $status < 600 ? $status : 502);
        }
    }
}
