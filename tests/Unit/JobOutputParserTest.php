<?php

namespace CipiGui\Tests\Unit;

use CipiGui\Services\JobOutputParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class JobOutputParserTest extends TestCase
{
    #[Test]
    public function it_lists_app_create_credentials_in_order_and_masks_secrets(): void
    {
        $rows = (new JobOutputParser)->secrets([
            'app' => 'shop',
            'ssh' => ['user' => 'shop', 'password' => 'ssh-pass'],
            'database' => ['user' => 'shop', 'password' => 'db-pass'],
            'deploy_key' => 'ssh-ed25519 AAAA cipi-shop',
            'webhook' => 'https://shop.example.com/cipi/webhook',
            'webhook_token' => 'token',
        ]);

        $this->assertSame(
            ['SSH / SFTP user', 'SSH password', 'Database user', 'Database password', 'Deploy key', 'Webhook URL', 'Webhook token'],
            array_column($rows, 'label'),
        );
        $this->assertSame([false, true, false, true, false, false, true], array_column($rows, 'masked'));
    }

    #[Test]
    public function it_lists_database_create_and_backup_results(): void
    {
        $parser = new JobOutputParser;

        $created = $parser->secrets(['engine' => 'pgsql', 'database' => 'analytics', 'user' => 'analytics', 'password' => 'p', 'url' => 'postgresql://…']);
        $this->assertSame(['Database', 'Engine', 'User', 'Password', 'Connection URL'], array_column($created, 'label'));

        $backup = $parser->secrets(['file' => '/home/cipi/backups/shop.sql.gz']);
        $this->assertSame([['label' => 'Backup file', 'value' => '/home/cipi/backups/shop.sql.gz', 'masked' => false]], $backup);

        $this->assertSame([], $parser->secrets(['app' => 'shop', 'deployed' => true]));
    }

    #[Test]
    public function it_reads_the_repository_access_check_and_the_build(): void
    {
        $parser = new JobOutputParser;

        $denied = $parser->repositoryAccess(['app' => 'shop', 'repository_access' => [
            'status' => 'denied', 'error' => ' ERROR: Repository not found. ', 'hint' => 'cipi git github-token <token> && cipi git refresh shop',
        ]]);
        $this->assertSame(['status' => 'denied', 'branch' => null, 'error' => 'ERROR: Repository not found.', 'hint' => 'cipi git github-token <token> && cipi git refresh shop'], $denied);

        $ok = $parser->repositoryAccess(['repository_access' => ['status' => 'ok', 'branch' => 'main']]);
        $this->assertSame(['status' => 'ok', 'branch' => 'main', 'error' => null, 'hint' => null], $ok);

        // Older servers, apps without a repository, or a shape the panel does not know.
        $this->assertNull($parser->repositoryAccess(['app' => 'shop']));
        $this->assertNull($parser->repositoryAccess(['repository_access' => ['status' => 'maybe']]));
        $this->assertNull($parser->repositoryAccess(['repository_access' => 'ok']));

        $this->assertSame('npm ci && npm run build', $parser->build(['build' => 'npm ci && npm run build']));
        $this->assertNull($parser->build(['build' => '  ']));
        $this->assertNull($parser->build([]));

        // Neither is a credential.
        $this->assertSame([], $parser->secrets(['build' => 'npm run build', 'repository_access' => ['status' => 'ok']]));
    }

    #[Test]
    public function it_reads_a_cipi_yml_apply_result(): void
    {
        $parser = new JobOutputParser;

        $partial = $parser->ymlApply([
            'app' => 'shop', 'outcome' => 'partial', 'applied' => '2', 'failed' => 1,
            'failures' => ['add queue worker emails (1 process(es))', ''], 'blockers' => [], 'errors' => null,
        ]);
        $this->assertSame([
            'outcome' => 'partial', 'applied' => 2, 'failed' => 1,
            'failures' => ['add queue worker emails (1 process(es))'], 'blockers' => [], 'errors' => [],
        ], $partial);

        $this->assertSame('cipi.yml applied in part: 1 change failed, 2 applied.', $parser->extractError('[ERROR] Applied 2 change(s), 1 failed', ['outcome' => 'partial', 'applied' => 2, 'failed' => 1]));
        $this->assertSame('cipi.yml not applied: 2 blockers to resolve first.', $parser->extractError('', ['outcome' => 'blocked', 'blockers' => ['a', 'b']]));
        $this->assertSame('cipi.yml is not valid — nothing was applied.', $parser->extractError('', ['outcome' => 'invalid', 'errors' => ['line 4: …']]));
        $this->assertSame('There is no cipi.yml in the current release.', $parser->extractError('', ['outcome' => 'no-file']));

        // Other jobs and unknown outcomes are not a cipi.yml result.
        $this->assertNull($parser->ymlApply(['app' => 'shop', 'deployed' => true]));
        $this->assertNull($parser->ymlApply(['outcome' => 'maybe']));
        $this->assertSame([], $parser->secrets(['app' => 'shop', 'outcome' => 'applied', 'applied' => 3]));
    }

    #[Test]
    public function it_extracts_the_cli_error_and_drops_deployer_usage_noise(): void
    {
        $parser = new JobOutputParser;
        $output = "task deploy:update_code\n[ERROR] Permission denied (publickey).\ndeploy [-p|--parallel] [-l|--limit LIMIT]";

        $this->assertSame('Permission denied (publickey).', $parser->extractError($output));
        $this->assertStringNotContainsString('--parallel', $parser->cleanOutput($output));
        $this->assertTrue($parser->isDeployFailure('', 'app-deploy'));
    }
}
