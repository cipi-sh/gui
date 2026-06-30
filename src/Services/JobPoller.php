<?php

namespace CipiGui\Services;

use CipiGui\Models\CipiServer;

class JobPoller
{
    public function __construct(
        protected CipiApiClient $client,
    ) {}

    public static function forServer(CipiServer $server): self
    {
        return new self(CipiApiClient::for($server));
    }

    /**
     * Poll a job until it completes or fails.
     *
     * @return array{status: string, data: array, output: ?string, exit_code: ?int}
     */
    public function poll(string $jobId, ?callable $onUpdate = null): array
    {
        $maxAttempts = config('cipi-gui.job_poll_max_attempts', 120);
        $intervalMs = config('cipi-gui.job_poll_interval_ms', 1500);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $data = $this->client->getJob($jobId);
            $status = $data['status'] ?? 'pending';

            if ($onUpdate) {
                $onUpdate($data);
            }

            if (in_array($status, ['completed', 'failed'], true)) {
                return [
                    'status' => $status,
                    'data' => $data,
                    'output' => $data['output'] ?? null,
                    'exit_code' => $data['exit_code'] ?? null,
                ];
            }

            usleep($intervalMs * 1000);
        }

        throw new CipiApiException('Job polling timed out. The operation may still be running on the server.', 408);
    }

    public function isFinished(array $jobData): bool
    {
        return in_array($jobData['status'] ?? '', ['completed', 'failed'], true);
    }
}
