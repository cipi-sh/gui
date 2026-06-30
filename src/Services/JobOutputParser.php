<?php

namespace CipiGui\Services;

class JobOutputParser
{
    /**
     * Deployer/Symfony append command usage as the last line on failure — not a real error.
     */
    private const DEPLOYER_USAGE_PATTERN = '/\n\s*deploy \[-p\|--parallel\].*$/s';

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
