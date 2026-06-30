<?php

namespace CipiGui\Livewire\Concerns;

use CipiGui\Services\JobOutputParser;

trait ManagesAsyncJobs
{
    public ?string $activeJobId = null;

    public ?string $activeJobStatus = null;

    public ?string $activeJobOutput = null;

    public ?array $activeJobResult = null;

    public bool $jobRunning = false;

    public bool $showJobOverlay = false;

    public string $jobLabel = '';

    public ?string $activeJobError = null;

    public bool $showDeployHints = false;

    /** @var array<int, string> */
    public array $deployHints = [];

    protected function dispatchJob(array $response, string $label): void
    {
        if (! isset($response['job_id'])) {
            return;
        }

        $this->activeJobId = $response['job_id'];
        $this->activeJobStatus = $response['status'] ?? 'pending';
        $this->activeJobOutput = null;
        $this->activeJobResult = null;
        $this->activeJobError = null;
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

            if (in_array($this->activeJobStatus, ['completed', 'failed'], true)) {
                $this->jobRunning = false;
                $rawOutput = $data['output'] ?? null;
                $parser = app(JobOutputParser::class);
                $this->activeJobOutput = $rawOutput ? $parser->cleanOutput($rawOutput) : null;
                $this->activeJobResult = $data['result'] ?? null;

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
            $this->activeJobError = $e->getMessage();
            $this->error = $e->getMessage();
        }
    }

    public function dismissJob(): void
    {
        $this->activeJobId = null;
        $this->activeJobStatus = null;
        $this->activeJobOutput = null;
        $this->activeJobResult = null;
        $this->activeJobError = null;
        $this->showDeployHints = false;
        $this->deployHints = [];
        $this->jobRunning = false;
        $this->showJobOverlay = false;
        $this->jobLabel = '';
    }

    protected function onJobCompleted(array $data): void {}

    protected function onJobFailed(array $data): void {}
}
