<?php

namespace CipiGui\Livewire\Concerns;

trait ManagesAsyncJobs
{
    public ?string $activeJobId = null;

    public ?string $activeJobStatus = null;

    public ?string $activeJobOutput = null;

    public ?array $activeJobResult = null;

    public bool $jobRunning = false;

    public string $jobLabel = '';

    protected function dispatchJob(array $response, string $label): void
    {
        if (! isset($response['job_id'])) {
            return;
        }

        $this->activeJobId = $response['job_id'];
        $this->activeJobStatus = $response['status'] ?? 'pending';
        $this->activeJobOutput = null;
        $this->activeJobResult = null;
        $this->jobRunning = true;
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
                $this->activeJobOutput = $data['output'] ?? null;
                $this->activeJobResult = $data['result'] ?? null;

                if ($this->activeJobStatus === 'completed') {
                    $this->dispatch('notify', type: 'success', message: "{$this->jobLabel} completed.");
                    $this->onJobCompleted($data);
                } else {
                    $error = $data['result']['error'] ?? 'Job failed.';
                    $this->dispatch('notify', type: 'error', message: $error);
                    $this->onJobFailed($data);
                }
            }
        } catch (\Throwable $e) {
            $this->jobRunning = false;
            $this->error = $e->getMessage();
        }
    }

    public function dismissJob(): void
    {
        $this->activeJobId = null;
        $this->activeJobStatus = null;
        $this->activeJobOutput = null;
        $this->activeJobResult = null;
        $this->jobRunning = false;
        $this->jobLabel = '';
    }

    protected function onJobCompleted(array $data): void {}

    protected function onJobFailed(array $data): void {}
}
