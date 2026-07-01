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
                if ($this->hasLogContent($fallback['files'] ?? [])) {
                    $this->logType = 'all';
                    $this->applyLogPayload($fallback);
                    $this->hint = 'No Laravel logs in shared/storage/logs/. Showing all log types instead.';
                }
            }

            if ($this->files === [] && $this->logType === 'laravel') {
                $this->hint = 'Laravel logs live in shared/storage/logs/ (laravel-YYYY-MM-DD.log). '
                    .'If the directory is missing or empty, trigger some app traffic or check logging on the server.';
            } elseif ($this->files === []) {
                $this->hint = 'No log output for filter "'.ucfirst($this->logType).'". Try "All logs" or generate activity on the app.';
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
        $this->files = $this->normalizeFiles($data['files'] ?? []);
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

    /** @param  array<int, array<string, mixed>>  $files */
    protected function hasLogContent(array $files): bool
    {
        return $this->normalizeFiles($files) !== [];
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
