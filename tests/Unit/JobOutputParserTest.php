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
    public function it_extracts_the_cli_error_and_drops_deployer_usage_noise(): void
    {
        $parser = new JobOutputParser;
        $output = "task deploy:update_code\n[ERROR] Permission denied (publickey).\ndeploy [-p|--parallel] [-l|--limit LIMIT]";

        $this->assertSame('Permission denied (publickey).', $parser->extractError($output));
        $this->assertStringNotContainsString('--parallel', $parser->cleanOutput($output));
        $this->assertTrue($parser->isDeployFailure('', 'app-deploy'));
    }
}
