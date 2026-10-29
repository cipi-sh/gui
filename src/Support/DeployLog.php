<?php

namespace CipiGui\Support;

/**
 * The deploys an app's deploy log holds, read from the banners Cipi 5.6+ writes
 * to it (lib/deploy.sh and lib/cipi-app-deploy.sh):
 *
 *   [ts] ===== deploy start  app=X trigger=T branch=B from-release=R =====
 *   [ts] ===== deploy [ROLLBACK ]OK|FAILED  app=X release=R duration=Ns exit=N =====
 *
 * The audit ledger (`cipi deploy --audit`) has no duration of its own and no
 * log lines, so the panel joins the two by release: a published deploy's banner
 * names the release that went live; a failed one names the release that stayed
 * current (the ledger names the one that was being built), so a failure also
 * matches the release the ledger shows as live just before it.
 */
final class DeployLog
{
    private const START = '/^(?:\[([^\]]*)\]\s*)?===== deploy start\s+app=\S+ trigger=(\S+) branch=(\S+) from-release=(\S+) =====/';

    private const BANNER = '/^(?:\[([^\]]*)\]\s*)?===== deploy (ROLLBACK )?(OK|FAILED)\s+app=\S+ release=(\S+) duration=(\d+)s exit=-?\d+ =====/';

    /**
     * Every end banner of a deploy log, oldest first.
     *
     * @param  iterable<mixed>  $lines
     * @return list<array{event: string, release: string, seconds: int}> event is the ledger's word: published | failed | rollback
     */
    public static function banners(iterable $lines): array
    {
        return array_map(
            fn (array $section) => ['event' => $section['event'], 'release' => $section['release'], 'seconds' => $section['seconds']],
            self::sections($lines),
        );
    }

    /**
     * The deploys the log holds, oldest first — one per end banner, with the
     * lines that belong to it: from its start banner (or, when that was rotated
     * out, the line after the previous deploy) to the line before the next start
     * banner, so the post-deploy steps, the cipi.yml apply and the healthcheck
     * verdict written after the end banner stay with their deploy. A start with
     * no end (a deploy still running, or one that was interrupted) is no section.
     *
     * @param  iterable<mixed>  $lines
     * @return list<array{event: string, release: string, seconds: int, trigger: ?string, branch: ?string, from_release: ?string, started_at: ?string, finished_at: ?string, from: int, end: int, to: int}>
     */
    public static function sections(iterable $lines): array
    {
        $events = [];
        $count = 0;

        foreach ($lines as $line) {
            $index = $count++;
            if (! is_string($line)) {
                continue;
            }
            if (preg_match(self::START, $line, $m)) {
                $events[] = ['kind' => 'start', 'index' => $index, 'ts' => $m[1] !== '' ? $m[1] : null, 'trigger' => $m[2], 'branch' => $m[3], 'from_release' => $m[4]];
            } elseif (preg_match(self::BANNER, $line, $m)) {
                $events[] = [
                    'kind' => 'end', 'index' => $index, 'ts' => $m[1] !== '' ? $m[1] : null,
                    'event' => $m[2] !== '' ? 'rollback' : ($m[3] === 'OK' ? 'published' : 'failed'),
                    'release' => $m[4], 'seconds' => (int) $m[5],
                ];
            }
        }

        $sections = [];
        $start = null;
        $previousEnd = -1;

        foreach ($events as $k => $event) {
            if ($event['kind'] === 'start') {
                $start = $event;

                continue;
            }

            // The trailing lines run to the next deploy's start banner. An end banner
            // with no start before it (the start rotated out) owns the lines before it,
            // so the section before it keeps none.
            $next = $events[$k + 1] ?? null;
            $to = match (true) {
                $next === null => $count - 1,
                $next['kind'] === 'start' => $next['index'] - 1,
                default => $event['index'],
            };

            $sections[] = [
                'event' => $event['event'],
                'release' => $event['release'],
                'seconds' => $event['seconds'],
                'trigger' => $start['trigger'] ?? null,
                'branch' => $start['branch'] ?? null,
                'from_release' => $start['from_release'] ?? null,
                'started_at' => $start['ts'] ?? null,
                'finished_at' => $event['ts'],
                'from' => $start['index'] ?? $previousEnd + 1,
                'end' => $event['index'],
                'to' => $to,
            ];
            $previousEnd = $event['index'];
            $start = null;
        }

        return $sections;
    }

    /**
     * The lines of one deploy, as sections() delimited it.
     *
     * @param  list<mixed>  $lines  the same list sections() was given
     * @param  array{from: int, to: int}  $section
     * @return list<string>
     */
    public static function slice(array $lines, array $section): array
    {
        $out = [];
        for ($i = max(0, $section['from']); $i <= $section['to'] && $i < count($lines); $i++) {
            if (is_string($lines[$i])) {
                $out[] = $lines[$i];
            }
        }

        return $out;
    }

    /**
     * Seconds each ledger record's deploy took, keyed like $records (newest first).
     *
     * @param  list<array<string, mixed>>  $records  deploy audit records, newest first
     * @param  list<array{event: string, release: string, seconds: int}>  $banners  oldest first
     * @return array<int, int>
     */
    public static function match(array $records, array $banners): array
    {
        $took = [];
        foreach (self::matchIndexes($records, $banners) as $index => $banner) {
            $took[$index] = $banners[$banner]['seconds'];
        }

        return $took;
    }

    /**
     * Which banner (or section) belongs to which ledger record: record index →
     * banner index. Every banner is used once, newest first, so a log that lost
     * its oldest part to rotation or pagination still matches what it kept.
     *
     * @param  list<array<string, mixed>>  $records  deploy audit records, newest first
     * @param  list<array{event: string, release: string}>  $banners  oldest first
     * @return array<int, int>
     */
    public static function matchIndexes(array $records, array $banners): array
    {
        $matched = [];
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
                    $matched[$index] = $i;
                    $free[$i] = false;

                    break;
                }
            }
        }

        return $matched;
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

    /**
     * Who started a deploy, as a phrase after "started by": the trigger word the
     * start banner carries (`cipi deploy --status` reports the same one).
     */
    public static function triggerLabel(?string $trigger): string
    {
        return match ($trigger) {
            'webhook' => 'a Git push',
            'panel' => 'the panel',
            'cli' => 'the command line',
            'rollback' => 'a rollback',
            'mcp' => 'cipi/agent (MCP)',
            null, '' => 'something outside Cipi (dep run by hand)',
            default => $trigger,
        };
    }
}
