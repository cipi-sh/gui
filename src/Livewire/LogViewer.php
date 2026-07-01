<?php

namespace CipiGui\Livewire;

use CipiGui\Livewire\Concerns\InteractsWithCipiServer;
use CipiGui\Services\CipiApiException;
use Livewire\Component;

class LogViewer extends Component
{
    use InteractsWithCipiServer;

    /** @var list<string> */
    private const LOG_TYPES = ['all', 'nginx', 'php', 'worker', 'deploy', 'laravel'];

    private const LARAVEL_LOG_PATH = '/shared/storage/logs/';

    public string $appName;

    public string $logType = 'all';

    public bool $isCustomApp = false;

    public int $page = 1;

    public int $perPage = 100;

    /** @var array<int, array> */
    public array $files = [];

    /** @var array<int, string> */
    public array $availableTypes = [];

    /** @var array<int, string> */
    public array $warnings = [];

    public ?string $hint = null;

    public bool $loading = true;

    public bool $autoRefresh = false;

    public function mount(string $app, ?int $serverId = null, bool $isCustomApp = false): void
    {
        $this->appName = $app;
        $this->serverId = $serverId ?? session('cipi_gui_server_id');
        $this->isCustomApp = filter_var($isCustomApp, FILTER_VALIDATE_BOOLEAN);
        $this->logType = 'all';
        $this->loadLogs();
    }

    public function updatedLogType(): void
    {
        $this->page = 1;
        $this->loadLogs();
    }

    public function updatedAutoRefresh(): void
    {
        if ($this->autoRefresh) {
            $this->page = 1;
        }
    }

    public function nextPage(): void
    {
        $this->page++;
        $this->loadLogs();
    }

    public function prevPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
            $this->loadLogs();
        }
    }

    public function refresh(): void
    {
        $this->loadLogs();
    }

    public function polledRefresh(): void
    {
        if ($this->autoRefresh) {
            $this->loadLogs();
        }
    }

    public function loadLogs(): void
    {
        $this->loading = true;
        $this->error = null;
        $this->hint = null;

        try {
            $data = $this->fetchLogs($this->logType);
            $this->applyLogPayload($data);

            if ($this->files === [] && $this->logType === 'laravel') {
                $fallback = $this->fetchLogs('all');
                $laravelFiles = $this->filterFilesForType($fallback['files'] ?? [], 'laravel');
                if ($laravelFiles !== []) {
                    $this->applyLogPayload(array_merge($fallback, ['files' => $laravelFiles]));
                    $this->hint = 'Loaded Laravel logs from the combined app log snapshot.';
                }
            }

            if ($this->files === [] && $this->logType === 'laravel') {
                $this->hint = 'Laravel logs were not returned by the server API. '
                    .'On the server run: cipi self-update && cipi api update, then test '
                    .'`sudo -u www-data sudo cipi app logs read '.$this->appName.' --type=laravel --page=1 --per-page=20`';
            } elseif ($this->files === []) {
                $this->hint = 'No log output for filter "'.ucfirst($this->logType).'". '
                    .'On the server test: `sudo -u www-data sudo cipi app logs read '.$this->appName.' --type=all --page=1 --per-page=20`';
            }
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
            $this->files = [];
        } finally {
            $this->loading = false;
        }
    }

    /** @return array<string, mixed> */
    protected function fetchLogs(string $type): array
    {
        return $this->client()->appLogs($this->appName, [
            'type' => $type,
            'page' => $this->page,
            'per_page' => $this->perPage,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    protected function applyLogPayload(array $data): void
    {
        $this->availableTypes = $data['available_types'] ?? [];
        $this->warnings = $data['warnings'] ?? [];
        $this->files = $this->normalizeFiles($this->filterFilesForType($data['files'] ?? [], $this->logType));
    }

    /** @param  array<int, array<string, mixed>>  $files */
    protected function filterFilesForType(array $files, string $type): array
    {
        if ($type !== 'laravel') {
            return $files;
        }

        return array_values(array_filter(
            $files,
            fn (array $file) => str_contains((string) ($file['path'] ?? ''), self::LARAVEL_LOG_PATH),
        ));
    }

    /** @param  array<int, array<string, mixed>>  $files */
    protected function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $file) {
            $lines = $file['lines'] ?? [];
            if (! is_array($lines)) {
                $lines = [];
            }

            $lines = array_values(array_filter($lines, fn ($line) => is_string($line) && $line !== ''));

            if ($lines === []) {
                continue;
            }

            $file['lines'] = $lines;
            $normalized[] = $file;
        }

        return $normalized;
    }

    /** @return list<string> */
    public function logTypeOptions(): array
    {
        $options = self::LOG_TYPES;

        foreach ($this->availableTypes as $type) {
            if (! in_array($type, $options, true)) {
                $options[] = $type;
            }
        }

        return $options;
    }

    public function render()
    {
        return view('cipi-gui::livewire.log-viewer');
    }
}
