<?php

namespace CipiGui\Tests\Unit;

use CipiGui\Support\DeployLog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DeployLogTest extends TestCase
{
    #[Test]
    public function banners_are_read_from_the_deploy_log(): void
    {
        $banners = DeployLog::banners([
            '[2026-10-24 08:54:00] ===== deploy start  app=shop trigger=webhook branch=main from-release=28 =====',
            '[2026-10-24 08:54:01] task deploy:info',
            '[2026-10-24 08:55:55] ===== deploy OK  app=shop release=29 duration=54s exit=0 =====',
            '',
            '[2026-10-25 10:00:40] ===== deploy FAILED  app=shop release=29 duration=40s exit=1 =====',
            '[2026-10-25 10:05:03] ===== deploy ROLLBACK OK  app=shop release=28 duration=3s exit=0 =====',
            42,
            null,
        ]);

        $this->assertSame([
            ['event' => 'published', 'release' => '29', 'seconds' => 54],
            ['event' => 'failed', 'release' => '29', 'seconds' => 40],
            ['event' => 'rollback', 'release' => '28', 'seconds' => 3],
        ], $banners);
    }

    #[Test]
    public function records_are_matched_newest_first_and_failures_on_the_release_that_stayed_current(): void
    {
        // Ledger, newest first: 31 published, 30 failed (29 stayed current), 29 published, 28 published (rotated out of the log).
        $records = [
            ['event' => 'published', 'release' => '31'],
            ['event' => 'failed', 'release' => '30'],
            ['event' => 'rollback', 'release' => '29'],
            ['event' => 'published', 'release' => '29'],
            ['event' => 'published', 'release' => '28'],
            ['event' => 'published', 'release' => '?'],
        ];
        $banners = [
            ['event' => 'published', 'release' => '29', 'seconds' => 50],
            ['event' => 'rollback', 'release' => '29', 'seconds' => 4],
            ['event' => 'failed', 'release' => '29', 'seconds' => 40],
            ['event' => 'published', 'release' => '31', 'seconds' => 61],
        ];

        $this->assertSame([0 => 61, 1 => 40, 2 => 4, 3 => 50], DeployLog::match($records, $banners));
    }

    #[Test]
    public function two_failures_in_a_row_each_get_their_own_banner(): void
    {
        // Both attempts were built while 29 was live, so both banners say release=29.
        $records = [
            ['event' => 'failed', 'release' => '31'],
            ['event' => 'failed', 'release' => '30'],
            ['event' => 'published', 'release' => '29'],
        ];
        $banners = [
            ['event' => 'published', 'release' => '29', 'seconds' => 48],
            ['event' => 'failed', 'release' => '29', 'seconds' => 20],
            ['event' => 'failed', 'release' => '29', 'seconds' => 35],
        ];

        $this->assertSame([0 => 35, 1 => 20, 2 => 48], DeployLog::match($records, $banners));
    }

    #[Test]
    public function a_failure_older_than_the_ledger_falls_back_to_the_previous_release_number(): void
    {
        $records = [['event' => 'failed', 'release' => '30']];
        $banners = [['event' => 'failed', 'release' => '29', 'seconds' => 20]];

        $this->assertSame([0 => 20], DeployLog::match($records, $banners));
    }

    #[Test]
    public function sections_delimit_each_deploy_with_the_lines_that_follow_its_end_banner(): void
    {
        $lines = [
            '[2026-10-24 08:54:00] ===== deploy start  app=shop trigger=webhook branch=main from-release=28 =====',
            '[2026-10-24 08:54:01] task deploy:info',
            '[2026-10-24 08:55:55] ===== deploy OK  app=shop release=29 duration=54s exit=0 =====',
            '',
            '[2026-10-24 08:55:56]  → Post-deploy healthcheck: https://shop.example.com/up (expect 200)',
            '[2026-10-24 08:55:58]  ✓ Healthcheck passed — HTTP 200',
            '[2026-10-25 10:00:00] ===== deploy start  app=shop trigger=panel branch=main from-release=29 =====',
            '[2026-10-25 10:00:40] ===== deploy FAILED  app=shop release=29 duration=40s exit=1 =====',
            '[2026-10-25 10:05:00] ===== deploy start  app=shop trigger=rollback branch=main from-release=29 =====',
            '[2026-10-25 10:05:03] ===== deploy ROLLBACK OK  app=shop release=28 duration=3s exit=0 =====',
            '',
            '[2026-10-26 09:00:00] ===== deploy start  app=shop trigger=webhook branch=main from-release=28 =====',
            '[2026-10-26 09:00:01] task deploy:info', // still running: no end banner, no section
        ];

        $sections = DeployLog::sections($lines);

        $this->assertCount(3, $sections);
        $this->assertSame(['event' => 'published', 'release' => '29', 'seconds' => 54, 'trigger' => 'webhook', 'branch' => 'main', 'from_release' => '28',
            'started_at' => '2026-10-24 08:54:00', 'finished_at' => '2026-10-24 08:55:55', 'from' => 0, 'end' => 2, 'to' => 5], $sections[0]);
        $this->assertSame(['event' => 'failed', 'release' => '29', 'trigger' => 'panel', 'from' => 6, 'end' => 7, 'to' => 7],
            array_intersect_key($sections[1], array_flip(['event', 'release', 'trigger', 'from', 'end', 'to'])));
        $this->assertSame(['event' => 'rollback', 'release' => '28', 'trigger' => 'rollback', 'from' => 8, 'end' => 9, 'to' => 10],
            array_intersect_key($sections[2], array_flip(['event', 'release', 'trigger', 'from', 'end', 'to'])));

        $this->assertSame(array_slice($lines, 0, 6), DeployLog::slice($lines, $sections[0]));
        $this->assertSame(array_slice($lines, 8, 3), DeployLog::slice($lines, $sections[2]));
        $this->assertSame(DeployLog::banners($lines), array_map(fn ($s) => ['event' => $s['event'], 'release' => $s['release'], 'seconds' => $s['seconds']], $sections));
    }

    #[Test]
    public function a_deploy_whose_start_banner_was_rotated_out_begins_after_the_previous_one(): void
    {
        $lines = [
            '[2026-10-24 08:54:01] task deploy:vendors',
            '[2026-10-24 08:55:55] ===== deploy OK  app=shop release=29 duration=54s exit=0 =====',
            '[2026-10-25 10:00:00] task deploy:info',
            '[2026-10-25 10:00:40] ===== deploy OK  app=shop release=30 duration=40s exit=0 =====',
        ];

        $sections = DeployLog::sections($lines);

        $this->assertSame([0, 1, 1], [$sections[0]['from'], $sections[0]['end'], $sections[0]['to']]);
        $this->assertNull($sections[0]['trigger']);
        $this->assertSame([2, 3, 3], [$sections[1]['from'], $sections[1]['end'], $sections[1]['to']]);
    }

    #[Test]
    public function match_indexes_name_the_banner_of_each_record(): void
    {
        $records = [
            ['event' => 'failed', 'release' => '30'],
            ['event' => 'published', 'release' => '29'],
            ['event' => 'published', 'release' => '27'],
        ];
        $banners = [
            ['event' => 'published', 'release' => '29', 'seconds' => 54],
            ['event' => 'failed', 'release' => '29', 'seconds' => 40],
        ];

        $this->assertSame([0 => 1, 1 => 0], DeployLog::matchIndexes($records, $banners));
        $this->assertSame([0 => 40, 1 => 54], DeployLog::match($records, $banners));
    }

    #[Test]
    public function the_trigger_word_of_a_banner_reads_as_who_started_the_deploy(): void
    {
        $this->assertSame('a Git push', DeployLog::triggerLabel('webhook'));
        $this->assertSame('the panel', DeployLog::triggerLabel('panel'));
        $this->assertSame('the command line', DeployLog::triggerLabel('cli'));
        $this->assertSame('a rollback', DeployLog::triggerLabel('rollback'));
        $this->assertSame('cipi/agent (MCP)', DeployLog::triggerLabel('mcp'));
        $this->assertSame('ci-bot', DeployLog::triggerLabel('ci-bot'));
        $this->assertSame('something outside Cipi (dep run by hand)', DeployLog::triggerLabel(null));
    }

    #[Test]
    public function seconds_are_formatted_like_the_cli(): void
    {
        $this->assertSame('54s', DeployLog::format(54));
        $this->assertSame('1m02s', DeployLog::format(62));
        $this->assertSame('12m00s', DeployLog::format(720));
        $this->assertSame('1h02m', DeployLog::format(3725));
        $this->assertSame('0s', DeployLog::format(-3));
    }
}
