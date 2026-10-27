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
                } elseif ($this->jobDeployPublished()) {
                    // The release went live and the command failed afterwards: the
                    // post-deploy steps, or Cipi before 5.8.1 exiting 1 by mistake.
                    $exitCode = is_numeric($data['exit_code'] ?? null) ? (int) $data['exit_code'] : null;
                    $this->activeJobError = $parser->publishedDeployError($rawOutput ?? '', $exitCode);
                    $this->showDeployHints = true;
                    $this->deployHints = $parser->publishedDeployHints();
                    $this->dispatch('notify', type: 'info', message: $this->activeJobError);
                    $this->onJobFailed($data);
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

    /**
     * Repository access check in a finished job's result (app create, app edit with
     * a new repository or branch — API 1.34+ / Cipi 5.6.0+); null when absent.
     *
     * @return array{status: string, branch: ?string, error: ?string, hint: ?string}|null
     */
    public function jobRepositoryAccess(): ?array
    {
        return app(JobOutputParser::class)->repositoryAccess($this->activeJobResult ?? []);
    }

    /** Build command in a finished app-create result (API 1.34+); null when absent. */
    public function jobBuild(): ?string
    {
        return app(JobOutputParser::class)->build($this->activeJobResult ?? []);
    }

    /**
     * Outcome of a finished cipi.yml apply (API 1.35+ / Cipi 5.6.4+); null for
     * every other job.
     *
     * @return array{outcome: string, applied: int, failed: int, failures: list<string>, blockers: list<string>, errors: list<string>}|null
     */
    public function jobYmlApply(): ?array
    {
        return app(JobOutputParser::class)->ymlApply($this->activeJobResult ?? []);
    }

    /**
     * A failed `app-deploy` job whose output says the release went live: the
     * deploy itself worked and what ran after it did not — or the server runs
     * Cipi before 5.8.1, which exited 1 after every successful deploy. The
     * overlay shows it as a warning with its own hints, not as a failed deploy.
     */
    public function jobDeployPublished(): bool
    {
        return $this->activeJobStatus === 'failed'
            && $this->activeJobType === 'app-deploy'
            && app(JobOutputParser::class)->deployPublished($this->activeJobOutput ?? '');
    }

    protected function onJobCompleted(array $data): void {}

    protected function onJobFailed(array $data): void {}
}
