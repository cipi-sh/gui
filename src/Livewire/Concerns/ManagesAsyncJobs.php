<?php

namespace CipiGui\Livewire\Concerns;

use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use CipiGui\Services\JobOutputParser;

trait ManagesAsyncJobs
{
    public ?string $activeJobId = null;

    public ?string $activeJobType = null;

    public ?string $activeJobStatus = null;

    public ?string $activeJobOutput = null;

    public ?array $activeJobResult = null;

    public ?int $activeJobStartedAt = null;

    public ?int $activeJobFinishedAt = null;

    public bool $jobRunning = false;

    public bool $showJobOverlay = false;

    public string $jobLabel = '';

    public ?string $activeJobError = null;

    public bool $showDeployHints = false;

    /** @var array<int, string> */
    public array $deployHints = [];

    /**
     * Send an async request and open the job overlay.
     *
     * @param  callable(CipiApiClient): array  $request
     */
    protected function startJob(string $label, callable $request): bool
    {
        try {
            $this->dispatchJob($request($this->client()), $label);

            return true;
        } catch (CipiApiException $e) {
            $this->handleApiError($e);

            return false;
        }
    }

    protected function dispatchJob(array $response, string $label): void
    {
        if (! isset($response['job_id'])) {
            return;
        }

        $this->activeJobId = $response['job_id'];
        $this->activeJobType = null;
        $this->activeJobStatus = $response['status'] ?? 'pending';
        $this->activeJobOutput = null;
        $this->activeJobResult = null;
        $this->activeJobError = null;
        $this->activeJobStartedAt = (int) floor(microtime(true) * 1000);
        $this->activeJobFinishedAt = null;
        $this->showDeployHints = false;
        $this->deployHints = [];
        $this->jobRunning = true;
        $this->showJobOverlay = true;
        $this->jobLabel = $label;
    }

    public function pollJob(): void
    {
        if (! $this->activeJobId || ! $this->jobRunning) {
            return;
        }

        try {
            $data = $this->client()->getJob($this->activeJobId);
            $this->activeJobStatus = $data['status'] ?? 'pending';
            $this->activeJobType = $data['type'] ?? $this->activeJobType;

            if (in_array($this->activeJobStatus, ['completed', 'failed'], true)) {
                $this->jobRunning = false;
                $this->activeJobFinishedAt = (int) floor(microtime(true) * 1000);
                $rawOutput = $data['output'] ?? null;
                $parser = app(JobOutputParser::class);
                $this->activeJobOutput = $rawOutput ? $parser->cleanOutput($rawOutput) : null;
                $this->activeJobResult = is_array($data['result'] ?? null) ? $data['result'] : null;

                if ($this->activeJobStatus === 'completed') {
                    $this->dispatch('notify', type: 'success', message: "{$this->jobLabel} completed.");
                    $this->onJobCompleted($data);
                } else {
                    $this->activeJobError = $parser->extractError($rawOutput ?? '', $this->activeJobResult);
                    $this->showDeployHints = $parser->isDeployFailure($rawOutput ?? '', $data['type'] ?? null);
                    $this->deployHints = $this->showDeployHints ? $parser->deployHints() : [];
                    $this->dispatch('notify', type: 'error', message: $this->activeJobError);
                    $this->onJobFailed($data);
                }
            }
        } catch (\Throwable $e) {
            $this->jobRunning = false;
            $this->activeJobStatus = 'failed';
            $this->activeJobError = $e instanceof CipiApiException ? $this->friendlyApiError($e) : $e->getMessage();
        }
    }

    public function dismissJob(): void
    {
        $this->activeJobId = null;
        $this->activeJobType = null;
        $this->activeJobStatus = null;
        $this->activeJobOutput = null;
        $this->activeJobResult = null;
        $this->activeJobError = null;
        $this->activeJobStartedAt = null;
        $this->activeJobFinishedAt = null;
        $this->showDeployHints = false;
        $this->deployHints = [];
        $this->jobRunning = false;
        $this->showJobOverlay = false;
        $this->jobLabel = '';
    }

    /**
     * One-time secrets returned by a finished job (app create, db create/password,
     * webhook rotation, basic auth) as label → value rows for the overlay.
     *
     * @return list<array{label: string, value: string, masked: bool}>
     */
    public function jobSecrets(): array
    {
        return app(JobOutputParser::class)->secrets($this->activeJobResult ?? []);
    }

    protected function onJobCompleted(array $data): void {}

    protected function onJobFailed(array $data): void {}
}
