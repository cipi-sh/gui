<?php

namespace CipiGui\Support;

/**
 * How long each deploy took, read from the banners Cipi 5.6+ writes to an app's
 * deploy log (lib/deploy.sh and lib/cipi-app-deploy.sh):
 *
 *   [ts] ===== deploy start  app=X trigger=T branch=B from-release=R =====
 *   [ts] ===== deploy [ROLLBACK ]OK|FAILED  app=X release=R duration=Ns exit=N =====
 *
 * The audit ledger (`cipi deploy --audit`) has no duration of its own, so the panel
 * joins the two by release: a published deploy's banner names the release that went
 * live; a failed one names the release that stayed current (the ledger names the one
 * that was being built), so a failure also matches the release the ledger shows as
 * live just before it.
 */
final class DeployLog
{
    private const BANNER = '/===== deploy (ROLLBACK )?(OK|FAILED)\s+app=\S+ release=(\S+) duration=(\d+)s exit=-?\d+ =====/';

    /**
     * Every end banner of a deploy log, oldest first.
     *
     * @param  iterable<mixed>  $lines
     * @return list<array{event: string, release: string, seconds: int}> event is the ledger's word: published | failed | rollback
     */
    public static function banners(iterable $lines): array
    {
        $banners = [];

        foreach ($lines as $line) {
            if (! is_string($line) || ! preg_match(self::BANNER, $line, $m)) {
                continue;
            }

            $banners[] = [
                'event' => $m[1] !== '' ? 'rollback' : ($m[2] === 'OK' ? 'published' : 'failed'),
                'release' => $m[3],
                'seconds' => (int) $m[4],
            ];
        }

        return $banners;
    }

    /**
     * Seconds each ledger record's deploy took, keyed like $records (newest first).
     * Every banner is used once, newest first, so a log that lost its oldest part to
     * rotation or pagination still matches what it kept.
     *
     * @param  list<array<string, mixed>>  $records  deploy audit records, newest first
     * @param  list<array{event: string, release: string, seconds: int}>  $banners  oldest first
     * @return array<int, int>
     */
    public static function match(array $records, array $banners): array
    {
        $took = [];
        $free = array_fill(0, count($banners), true);

        foreach ($records as $index => $record) {
            $release = (string) ($record['release'] ?? '');
            $event = (string) ($record['event'] ?? 'published');
            if ($release === '' || $release === '?') {
                continue;
            }

            $wanted = [$release];
            if ($event === 'failed') {
                $wanted[] = self::liveBefore($records, $index) ?? (ctype_digit($release) && (int) $release > 1 ? (string) ((int) $release - 1) : $release);
            }

            for ($i = count($banners) - 1; $i >= 0; $i--) {
                if ($free[$i] && $banners[$i]['event'] === $event && in_array($banners[$i]['release'], $wanted, true)) {
                    $took[$index] = $banners[$i]['seconds'];
                    $free[$i] = false;

                    break;
                }
            }
        }

        return $took;
    }

    /**
     * The release that was live when record $index was written: the nearest older
     * published deploy or rollback in the ledger (newest first); null when the ledger
     * has none that old.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private static function liveBefore(array $records, int $index): ?string
    {
        for ($i = $index + 1, $n = count($records); $i < $n; $i++) {
            $release = (string) ($records[$i]['release'] ?? '');
            if (in_array($records[$i]['event'] ?? '', ['published', 'rollback'], true) && $release !== '' && $release !== '?') {
                return $release;
            }
        }

        return null;
    }

    /** 54s, 1m02s, 1h02m — the CLI's own format (`_deploy_fmt_secs` in lib/deploy.sh). */
    public static function format(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return match (true) {
            $seconds < 60 => $seconds.'s',
            $seconds < 3600 => sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60),
            default => sprintf('%dh%02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)),
        };
    }
}
