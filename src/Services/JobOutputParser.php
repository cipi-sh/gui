<?php

namespace CipiGui\Services;

class JobOutputParser
{
    /**
     * Deployer/Symfony append command usage as the last line on failure — not a real error.
     */
    private const DEPLOYER_USAGE_PATTERN = '/\n\s*deploy \[-p\|--parallel\].*$/s';

    /** Outcomes of `cipi yml apply` in an `app-yml-apply` job result. */
    public const YML_OUTCOMES = ['applied', 'unchanged', 'partial', 'blocked', 'invalid', 'no-file'];

    public function stripAnsi(string $text): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $text) ?? $text;
    }

    public function cleanOutput(string $output): string
    {
        $text = $this->stripAnsi($output);
        $text = preg_replace(self::DEPLOYER_USAGE_PATTERN, '', $text) ?? $text;

        return rtrim($text);
    }

    /**
     * Extract a human-readable error from async job CLI output.
     */
    public function extractError(string $output, ?array $result = null): string
    {
        if (! empty($result['error']) && ! $this->isDeployerUsageLine($result['error'])) {
            return (string) $result['error'];
        }

        if ($yml = $this->ymlApplyError($result)) {
            return $yml;
        }

        $text = $this->cleanOutput($output);

        if (preg_match('/\[ERROR\]\s*(.+?)(?:\n|$)/', $text, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/Deploy failed \(exit \d+\)/', $text, $m)) {
            return $m[0];
        }

        if (preg_match('/In Client\.php line \d+:\s*\n\s*\n\s*(.+?)(?:\n\s*\n|$)/s', $text, $m)) {
            return 'Deployer: '.trim($m[1]);
        }

        if (preg_match('/The command ".+?" failed\.\s*\n\s*\n\s*Exit Code:.*?\n\s*\n\s*Host Name:.*?\n\s*\n\s*=+\s*\n\s*(.+?)(?:\n\s*\n|$)/s', $text, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/(ssh: connect to host .+)$/m', $text, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/(fatal: .+)$/m', $text, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/(Could not read from remote repository\.)/', $text, $m)) {
            return $m[1];
        }

        if (preg_match('/(Permission denied \(publickey\)\.?)/', $text, $m)) {
            return $m[1];
        }

        if (preg_match('/(Composer[^\n]+failed[^\n]*)/i', $text, $m)) {
            return trim($m[1]);
        }

        $lines = array_values(array_filter(
            explode("\n", $text),
            fn ($line) => trim($line) !== '' && ! $this->isDeployerUsageLine($line) && ! $this->isNoiseLine($line),
        ));

        if ($lines !== []) {
            return trim(end($lines));
        }

        return 'Job failed. See CLI output for details.';
    }

    public function isDeployFailure(string $output, ?string $jobType = null): bool
    {
        if (in_array($jobType, ['app-deploy', 'app-deploy-rollback', 'app-deploy-unlock'], true)) {
            return true;
        }

        return str_contains($this->stripAnsi($output), 'dep deploy')
            || str_contains($this->stripAnsi($output), 'Deploy failed');
    }

    /**
     * @return array<int, string>
     */
    public function deployHints(): array
    {
        return [
            'Verify the app has a Git repository configured (Laravel apps require one).',
            'Add the deploy key to your Git provider: cipi deploy <app> --key',
            'Trust a custom Git host if needed: cipi deploy <app> --trust-host=git.example.com',
            'Run manually on the server: cipi deploy <app>',
            'If deploy is stuck: use Unlock deploy, then retry.',
        ];
    }

    /**
     * Whether a deploy's output says the release went live: Deployer's closing
     * line, or Cipi's own. A failed `app-deploy` job that carries one did not
     * fail to deploy — what ran afterwards (the post-deploy steps) did, or the
     * server runs Cipi before 5.8.1, which exited 1 after every successful deploy.
     */
    public function deployPublished(string $output): bool
    {
        $text = $this->stripAnsi($output);

        return str_contains($text, 'Successfully deployed!')
            || preg_match('/^\s*✓ Deploy completed/mu', $text) === 1;
    }

    /**
     * The error of a failed deploy whose release is live ({@see deployPublished}):
     * the first `[ERROR]` or `[WARN]` line printed after the release went live
     * (`Post-deploy: 1 of 2 failed`), or the exit code alone.
     */
    public function publishedDeployError(string $output, ?int $exitCode = null): string
    {
        $text = $this->cleanOutput($output);
        $marks = array_filter(
            [strpos($text, 'Successfully deployed!'), strpos($text, '✓ Deploy completed')],
            fn ($pos) => $pos !== false,
        );
        $after = $marks === [] ? $text : substr($text, min($marks));
        $how = $exitCode !== null ? "exited with code {$exitCode}" : 'exited with an error';

        if (preg_match('/\[(?:ERROR|WARN)\]\s*(.+?)(?:\n|$)/', $after, $m)) {
            return "The release is live, but cipi deploy {$how} afterwards: ".trim($m[1]);
        }

        return "The release is live, but cipi deploy {$how} afterwards without saying why. "
            .'Check the deploy log. Cipi before 5.8.1 exits 1 after every successful deploy: run cipi self-update on the server.';
    }

    /**
     * What to check when a deploy published and the command still failed.
     *
     * @return array<int, string>
     */
    public function publishedDeployHints(): array
    {
        return [
            'The release is live: nothing was rolled back.',
            'Check the post-deploy steps of cipi.yml and the deploy log (App → Logs).',
            'Cipi before 5.8.1 exits 1 after every successful deploy started from the API: run cipi self-update on the server.',
        ];
    }

    /**
     * Credentials and one-time values from a job result, in display order.
     *
     * @param  array<string, mixed>  $result
     * @return list<array{label: string, value: string, masked: bool}>
     */
    public function secrets(array $result): array
    {
        $rows = [];
        $add = function (string $label, mixed $value, bool $masked = false) use (&$rows): void {
            if (is_scalar($value) && (string) $value !== '') {
                $rows[] = ['label' => $label, 'value' => (string) $value, 'masked' => $masked];
            }
        };

        $ssh = is_array($result['ssh'] ?? null) ? $result['ssh'] : [];
        $add('SSH / SFTP user', $ssh['user'] ?? null);
        $add('SSH password', $ssh['password'] ?? null, true);

        $database = is_array($result['database'] ?? null) ? $result['database'] : [];
        $add('Database user', $database['user'] ?? null);
        $add('Database password', $database['password'] ?? null, true);

        if (! isset($result['ssh'])) {
            $add('Database', is_string($result['database'] ?? null) ? $result['database'] : null);
            $add('Engine', $result['engine'] ?? null);
            $add('User', $result['user'] ?? null);
            $add('Password', $result['password'] ?? null, true);
            $add('Connection URL', $result['url'] ?? null, true);
        }

        $add('Deploy key', $result['deploy_key'] ?? null);
        $add('Webhook URL', $result['webhook'] ?? $result['webhook_url'] ?? null);
        $add('Webhook token', $result['webhook_token'] ?? null, true);
        $add('Backup file', $result['file'] ?? null);

        return $rows;
    }

    /**
     * Whether the app's deploy key can read its repository, as checked by Cipi 5.6.0+
     * on app create and on app edit with a new repository or branch (API 1.34+).
     * Null when the result carries no check: older servers, apps without a repository.
     *
     * @param  array<string, mixed>  $result
     * @return array{status: 'ok'|'nobranch'|'denied', branch: ?string, error: ?string, hint: ?string}|null
     */
    public function repositoryAccess(array $result): ?array
    {
        $access = $result['repository_access'] ?? null;
        if (! is_array($access) || ! in_array($access['status'] ?? null, ['ok', 'nobranch', 'denied'], true)) {
            return null;
        }

        $text = fn (mixed $value): ?string => is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;

        return [
            'status' => $access['status'],
            'branch' => $text($access['branch'] ?? null),
            'error' => $text($access['error'] ?? null),
            'hint' => $text($access['hint'] ?? null),
        ];
    }

    /**
     * Build command reported by app create (API 1.34+): a Laravel app's asset
     * build or a Node app's build command.
     *
     * @param  array<string, mixed>  $result
     */
    public function build(array $result): ?string
    {
        $build = $result['build'] ?? null;

        return is_string($build) && trim($build) !== '' ? trim($build) : null;
    }

    /**
     * Result of a cipi.yml apply (job `app-yml-apply`, API 1.35+ / Cipi 5.6.4+).
     * `partial`, `blocked`, `invalid` and `no-file` end as failed jobs but still
     * carry the result. Null when the result is not one.
     *
     * @param  array<string, mixed>  $result
     * @return array{outcome: string, applied: int, failed: int, failures: list<string>, blockers: list<string>, errors: list<string>}|null
     */
    public function ymlApply(array $result): ?array
    {
        $outcome = $result['outcome'] ?? null;
        if (! in_array($outcome, self::YML_OUTCOMES, true)) {
            return null;
        }

        $lines = fn (mixed $list): array => is_array($list)
            ? array_values(array_map('strval', array_filter($list, fn ($l) => is_scalar($l) && trim((string) $l) !== '')))
            : [];

        return [
            'outcome' => $outcome,
            'applied' => is_numeric($result['applied'] ?? null) ? (int) $result['applied'] : 0,
            'failed' => is_numeric($result['failed'] ?? null) ? (int) $result['failed'] : 0,
            'failures' => $lines($result['failures'] ?? null),
            'blockers' => $lines($result['blockers'] ?? null),
            'errors' => $lines($result['errors'] ?? null),
        ];
    }

    /** One line for a cipi.yml apply that did not (fully) apply; null otherwise. */
    private function ymlApplyError(?array $result): ?string
    {
        $yml = $this->ymlApply($result ?? []);
        if ($yml === null) {
            return null;
        }

        $count = fn (int $n, string $word): string => $n.' '.$word.($n === 1 ? '' : 's');

        return match ($yml['outcome']) {
            'partial' => 'cipi.yml applied in part: '.$count($yml['failed'], 'change').' failed, '.$yml['applied'].' applied.',
            'blocked' => 'cipi.yml not applied: '.$count(count($yml['blockers']), 'blocker').' to resolve first.',
            'invalid' => 'cipi.yml is not valid — nothing was applied.',
            'no-file' => 'There is no cipi.yml in the current release.',
            default => null,
        };
    }

    private function isDeployerUsageLine(string $line): bool
    {
        return (bool) preg_match('/^deploy \[-p\|--parallel\]/', trim($line));
    }

    private function isNoiseLine(string $line): bool
    {
        $trimmed = trim($line);

        return in_array($trimmed, ['✔ Ok', '➤ Executing task deploy:failed', '==============='], true)
            || str_starts_with($trimmed, '➤ Executing task ');
    }
}
