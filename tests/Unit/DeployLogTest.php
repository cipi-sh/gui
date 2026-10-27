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
    public function seconds_are_formatted_like_the_cli(): void
    {
        $this->assertSame('54s', DeployLog::format(54));
        $this->assertSame('1m02s', DeployLog::format(62));
        $this->assertSame('12m00s', DeployLog::format(720));
        $this->assertSame('1h02m', DeployLog::format(3725));
        $this->assertSame('0s', DeployLog::format(-3));
    }
}
