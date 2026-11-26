<?php
// Standalone checks for the infrastructure connectors (php tests/standalone-infrastructure.php,
// no vendor/): every connector's definition, the lists read from canned answers shaped
// like each provider's, the DNS targets that are servers of the panel, OVH's signature.
// Laravel's helpers are stood in for by the little they need here; the PHPUnit suite
// (tests/Feature/InfrastructureTest.php) drives the pages with Laravel itself.
namespace CipiGui\Models {
    class CipiServer
    {
        /** @var list<object> */
        public static array $rows = [];
        public static function query(): object
        {
            return new class {
                public function orderBy(string $c): static { return $this; }
                public function get(array $cols = []): array { return CipiServer::$rows; }
            };
        }
    }
}
namespace Illuminate\Http\Client {
    class ConnectionException extends \Exception {}
    class Response {}
    class PendingRequest {}
}
namespace Illuminate\Support\Facades {
    class Http {}
    class Cache {}
}
namespace Illuminate\Support {
    class Str
    {
        public static function afterLast(string $s, string $search): string { $p = strrpos($s, $search); return $p === false ? $s : substr($s, $p + strlen($search)); }
        public static function uuid(): string { return '00000000-0000-4000-8000-000000000000'; }
        public static function limit(string $s, int $n = 100): string { return mb_strlen($s) > $n ? mb_substr($s, 0, $n).'...' : $s; }
        public static function lower(string $s): string { return mb_strtolower($s); }
        public static function startsWith(string $s, string $n): bool { return str_starts_with($s, $n); }
        public static function contains(string $s, string $n): bool { return str_contains($s, $n); }
        public static function random(int $n = 16): string { return substr(str_repeat('abc123', $n), 0, $n); }
    }
    class Carbon
    {
        public static function parse(string $v): object { return new class($v) { public function __construct(public string $v) {} public function diffForHumans(): string { return 'ago'; } }; }
    }
}
namespace {
    $root = $argv[1] ?? dirname(__DIR__);

    final class ShimCollection implements \IteratorAggregate, \Countable
    {
        public function __construct(public array $items = []) {}
        public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->items); }
        public function count(): int { return count($this->items); }
        public function all(): array { return $this->items; }
        public function values(): self { return new self(array_values($this->items)); }
        public function keys(): self { return new self(array_keys($this->items)); }
        public function map(callable $f): self { $k = array_keys($this->items); return new self(array_combine($k, array_map($f, $this->items, $k)) ?: []); }
        public function mapWithKeys(callable $f): self { $o = []; foreach ($this->items as $k => $v) { foreach ($f($v, $k) as $kk => $vv) { $o[$kk] = $vv; } } return new self($o); }
        public function filter(?callable $f = null): self { return new self(array_filter($this->items, $f ?? fn ($v) => (bool) $v)); }
        public function where(string $key, mixed $value): self { return new self(array_filter($this->items, fn ($v) => data_get($v, $key) == $value)); }
        public function pluck(string $key, ?string $by = null): self { $o = []; foreach ($this->items as $v) { $by === null ? $o[] = data_get($v, $key) : $o[data_get($v, $by)] = data_get($v, $key); } return new self($o); }
        public function implode(string $glue, ?string $key = null): string { return implode($key === null ? $glue : $key, array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $key === null ? $this->items : array_map(fn ($v) => data_get($v, $glue), $this->items))); }
        public function first(?callable $f = null, mixed $d = null): mixed { foreach ($this->items as $k => $v) { if ($f === null || $f($v, $k)) { return $v; } } return $d; }
        public function firstWhere(string $key, mixed $value): mixed { return $this->where($key, $value)->first(); }
        public function flatten(): self { $o = []; array_walk_recursive($this->items, function ($v) use (&$o) { $o[] = $v; }); return new self($o); }
        public function sum(?string $key = null): float|int { return array_sum($key === null ? $this->items : array_map(fn ($v) => data_get($v, $key), $this->items)); }
        public function sortDesc(): self { $i = $this->items; arsort($i); return new self($i); }
        public function sortKeys(): self { $i = $this->items; ksort($i); return new self($i); }
        public function sortByDesc(string|callable $k): self { $i = $this->items; uasort($i, fn ($a, $b) => (is_callable($k) ? $k($b) : data_get($b, $k)) <=> (is_callable($k) ? $k($a) : data_get($a, $k))); return new self($i); }
        public function merge(array|self $o): self { return new self(array_merge($this->items, $o instanceof self ? $o->items : $o)); }
        public function get(mixed $k, mixed $d = null): mixed { return $this->items[$k] ?? $d; }
        public function isEmpty(): bool { return $this->items === []; }
        public function isNotEmpty(): bool { return $this->items !== []; }
        public function toArray(): array { return $this->items; }
        public function unique(): self { return new self(array_unique($this->items, SORT_REGULAR)); }
    }
    function collect(mixed $v = []): ShimCollection { return new ShimCollection($v instanceof ShimCollection ? $v->items : (is_array($v) ? $v : ($v === null ? [] : [$v]))); }
    function __(string $s, array $r = []): string { foreach ($r as $k => $v) { $s = str_replace(':'.$k, (string) $v, $s); } return $s; }
    function trans_choice(string $s, int|float $n, array $r = []): string { $parts = explode('|', $s); return __($parts[$n == 1 ? 0 : (count($parts) - 1)], ['count' => $n] + $r); }
    function tap(mixed $v, ?callable $f = null): mixed { if ($f) { $f($v); } return $v; }
    function app(string $class): object { return new $class; }
    function class_basename(string|object $c): string { $c = is_object($c) ? get_class($c) : $c; return basename(str_replace('\\', '/', $c)); }
    function report(\Throwable $e): void {}
    function now(): object { return new class { public function startOfMonth(): static { return $this; } public function addDay(): static { return $this; } public function toDateString(): string { return '2026-11-01'; } public function toIso8601String(): string { return '2026-11-25T00:00:00Z'; } }; }
    function data_get(mixed $target, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) { return $target; }
        foreach (explode('.', (string) $key) as $segment) {
            if (is_array($target) && array_key_exists($segment, $target)) { $target = $target[$segment]; }
            elseif (is_object($target) && isset($target->{$segment})) { $target = $target->{$segment}; }
            else { return $default instanceof \Closure ? $default() : $default; }
        }
        return $target;
    }

    spl_autoload_register(function (string $class) use ($root) {
        if (str_starts_with($class, 'CipiGui\\')) {
            $file = $root.'/src/'.str_replace('\\', '/', substr($class, 8)).'.php';
            if (is_file($file)) { require $file; }
        }
    });

    // A provider, as canned answers: "METHOD path" (query left out) => answer; every call is kept.
    final class FakeApi extends CipiGui\Infrastructure\ScopedClient
    {
        public array $calls = [];
        public function __construct(public array $answers = [], array $scope = []) { parent::__construct('https://api.test', [], $scope); }
        public function send(string $method, string $path, array $options = []): array
        {
            $bare = (string) preg_replace('#^https://[^/]+#', '', explode('?', $path)[0]);
            $this->calls[] = [$method, $bare, $options];
            foreach ([$method.' '.$bare, $method.' '.$path] as $key) {
                if (array_key_exists($key, $this->answers)) { $a = $this->answers[$key]; return is_callable($a) ? $a($options) : $a; }
            }
            return [];
        }
        public function sent(string $method, string $bare): ?array { foreach ($this->calls as $c) { if ($c[0] === $method && $c[1] === $bare) { return $c[2]; } } return null; }
    }

    use CipiGui\Infrastructure\Connectors;
    use CipiGui\Infrastructure\ConnectorException;
    use CipiGui\Infrastructure\ServerTargets;
    use CipiGui\Support\Icons;

    $pass = 0; $fail = 0;
    $check = function (bool $ok, string $what) use (&$pass, &$fail): void {
        if ($ok) { $pass++; echo "  OK: {$what}\n"; } else { $fail++; fwrite(STDERR, "  FAIL: {$what}\n"); }
    };
    $threw = function (callable $f): ?string { try { $f(); } catch (\Throwable $e) { return $e->getMessage(); } return null; };
    // Every cell of every row as the page shows it: no column may throw.
    $render = function (CipiGui\Infrastructure\Section $section, array $rows): array {
        $out = [];
        foreach ($rows as $row) { $cells = []; foreach ($section->columns as $c) { $cells[$c->name] = $c->text($row); } $out[] = $cells; }
        return $out;
    };

    echo "--- every connector\n";
    $all = Connectors::all();
    $check(array_keys($all) === ['cloudflare', 'digitalocean', 'hetzner', 'aws', 'gcp', 'azure', 'ovh', 'vultr', 'linode', 'scaleway', 'contabo', 'upcloud', 'porkbun', 'gandi', 'namecheap', 'johnny', 'backblaze', 'wasabi', 'postmark', 'mailgun', 'resend', 'boogle', 'ohdear', 'betterstack', 'sentry', 'datadog', 'aikido', 'hackly'], 'twenty-eight connectors, Cloudflare first');
    $groups = Connectors::grouped();
    $in = fn (string $cat) => array_map(fn ($e) => $e['connector']->key(), $groups[$cat] ?? []);
    $check(array_keys($groups) === array_keys(Connectors::CATEGORIES), 'Settings → Connectors in six groups: clouds, domains, storage, email, monitoring, security');
    $check($in('storage') === ['johnny', 'backblaze', 'wasabi', 'cloudflare', 'digitalocean', 'aws'] && $in('email') === ['postmark', 'mailgun', 'resend', 'aws'] && $in('monitoring') === ['boogle', 'ohdear', 'betterstack', 'sentry', 'datadog', 'aws'] && $in('security') === ['aikido', 'hackly'], 'R2, Spaces and S3 under storage, SES under email, CloudWatch under monitoring, next to the providers of their own');
    $check(in_array('cloudflare', $in('dns'), true) && ! in_array('cloudflare', $in('cloud'), true) && str_contains((string) ($groups['storage'][3]['blurb'] ?? ''), 'R2'), 'Cloudflare is a DNS provider, listed with storage for R2 with what it does there');
    foreach ($all as $key => $c) {
        $sections = $c->sections();
        $keys = array_map(fn ($s) => $s->key, $sections);
        $problems = [];
        if ($c->key() !== $key) { $problems[] = 'key'; }
        if ($c->label() === '' || $c->description() === '' || ! str_starts_with($c->docs(), 'https://')) { $problems[] = 'label/description/docs'; }
        if ($c->fields() === []) { $problems[] = 'no fields'; }
        if (count($c->instructions()) < 2) { $problems[] = 'instructions'; }
        if (count($keys) !== count(array_unique($keys))) { $problems[] = 'duplicate sections'; }
        if (! isset(Icons::PATHS[$c->icon()]) && ! isset(Icons::ALIASES[$c->icon()])) { $problems[] = 'icon '.$c->icon(); }
        foreach ($sections as $s) {
            if ($s->list === null) { $problems[] = $s->key.' has no list'; }
            if ($s->kind === 'table' && $s->columns === []) { $problems[] = $s->key.' has no columns'; }
            if ($s->parent !== null && ! in_array($s->parent, $keys, true)) { $problems[] = $s->key.' parent '.$s->parent; }
            if (! $s->isChild() && ! isset(Icons::PATHS[$s->icon]) && ! isset(Icons::ALIASES[$s->icon])) { $problems[] = $s->key.' icon '.$s->icon; }
            $ak = array_map(fn ($a) => $a->key, $s->actions);
            if (count($ak) !== count(array_unique($ak))) { $problems[] = $s->key.' duplicate actions'; }
            foreach ([...$s->actions, ...$s->toolbar] as $a) {
                if ($a->opens !== null && ! in_array($a->opens, $keys, true)) { $problems[] = $s->key.'.'.$a->key.' opens '.$a->opens; }
                if ($a->opens === null && $a->run === null) { $problems[] = $s->key.'.'.$a->key.' does nothing'; }
                if (! isset(Icons::PATHS[$a->icon]) && ! isset(Icons::ALIASES[$a->icon])) { $problems[] = $s->key.'.'.$a->key.' icon '.$a->icon; }
            }
        }
        $check($problems === [], "{$key}: complete — ".count($sections).' lists'.($problems ? ' — '.implode('; ', $problems) : ''));
    }
    $secrets = [];
    foreach ($all as $key => $c) { foreach ($c->fields() as $f) { if (preg_match('/token|secret|key$/', $f->name) && $f->name !== 'application_key' && $f->name !== 'access_key_id' && ! $f->secret) { $secrets[] = $key.'.'.$f->name; } } }
    $check($secrets === [], 'every token, secret and key field is secret (never sent back to the page)'.($secrets ? ': '.implode(', ', $secrets) : ''));

    echo "--- a server of the panel as a DNS target\n";
    CipiGui\Models\CipiServer::$rows = [(object) ['name' => 'mozart', 'ip' => '203.0.113.10'], (object) ['name' => 'bach', 'ip' => '2001:db8::7'], (object) ['name' => 'nohost', 'ip' => null]];
    ServerTargets::flush();
    $check(ServerTargets::servers() === ['mozart' => '203.0.113.10', 'bach' => '2001:db8::7'], 'the servers with an address, by name');
    $check(ServerTargets::resolve('A', 'mozart') === '203.0.113.10' && ServerTargets::resolve('a', ' Mozart ') === '203.0.113.10', 'A record to "mozart": its address (case and spaces aside)');
    $check(ServerTargets::resolve('AAAA', 'bach') === '2001:db8::7', 'AAAA to an IPv6 server');
    $check(ServerTargets::resolve('A', '198.51.100.4') === '198.51.100.4' && ServerTargets::resolve('CNAME', 'edge.example.net') === 'edge.example.net', 'any other value as typed');
    $check(str_contains((string) $threw(fn () => ServerTargets::resolve('A', 'bach')), 'use an AAAA record') && str_contains((string) $threw(fn () => ServerTargets::resolve('AAAA', 'mozart')), 'use an A record'), 'the wrong family is refused, saying which record to use');
    $check(str_contains((string) $threw(fn () => ServerTargets::resolve('CNAME', 'mozart')), 'A and AAAA records'), 'a CNAME to a server is refused');
    $check(ServerTargets::label('203.0.113.10') === '203.0.113.10 → mozart' && ServerTargets::label('198.51.100.4') === '198.51.100.4', 'a list shows which server an address is');
    $check(ServerTargets::options() === ['mozart' => 'mozart · 203.0.113.10', 'bach' => 'bach · 2001:db8::7'], 'suggestions: each server by name, with its address');
    $check(ServerTargets::resolveAll('A', ['mozart', '198.51.100.4']) === ['203.0.113.10', '198.51.100.4'], 'a record set, line by line');

    echo "--- Cloudflare\n";
    $cf = Connectors::make('cloudflare');
    $api = new FakeApi([
        'GET /zones' => ['result' => [['id' => 'z1', 'name' => 'example.com', 'status' => 'active', 'paused' => false, 'plan' => ['name' => 'Free'], 'name_servers' => ['a.ns.cloudflare.com'], 'account' => ['name' => 'Acme']]], 'result_info' => ['total_pages' => 1]],
        'GET /zones/z1/dns_records' => ['result' => [['id' => 'r1', 'type' => 'A', 'name' => 'shop.example.com', 'content' => '203.0.113.10', 'proxied' => true, 'ttl' => 1]], 'result_info' => ['total_pages' => 1]],
        'GET /zones/z1/firewall/access_rules/rules' => ['result' => [['id' => 'ar1', 'mode' => 'block', 'configuration' => ['target' => 'ip', 'value' => '192.0.2.66'], 'scope' => ['type' => 'zone'], 'notes' => 'bot']], 'result_info' => ['total_pages' => 1]],
        'POST /zones/z1/dns_records' => ['result' => ['id' => 'r2']],
        'POST /zones/z1/firewall/access_rules/rules' => ['result' => ['id' => 'ar2']],
    ]);
    $zones = $cf->section('zones');
    $rows = ($zones->list)($api, []);
    $check($render($zones, $rows)[0]['name'] === 'example.com' && $render($zones, $rows)[0]['plan.name'] === 'Free', 'zones: listed and drawn');
    $records = $cf->section('records');
    $rows = ($records->list)($api, ['zone' => 'z1', 'zone_name' => 'example.com']);
    $check($render($records, $rows)[0]['content'] === '203.0.113.10 → mozart', 'DNS records: an address of the panel says which server');
    $create = $records->toolbarAction('create');
    ($create->run)($api, [], ['type' => 'A', 'name' => 'api', 'content' => 'mozart', 'proxied' => true, 'ttl' => null, 'priority' => null, 'comment' => null], ['zone' => 'z1']);
    $body = $api->sent('POST', '/zones/z1/dns_records')['json'] ?? [];
    $check(($body['content'] ?? null) === '203.0.113.10' && ($body['proxied'] ?? null) === true && ($body['ttl'] ?? null) === 1 && ! array_key_exists('priority', $body), 'new record "api → mozart", proxied: its address goes to Cloudflare, TTL auto, nothing empty');
    $edit = $records->action('edit');
    $check((string) ($edit->fields[2]->defaultFor(['content' => '203.0.113.10'])) === 'mozart' && $edit->fields[2]->type === 'suggest', 'editing a record that points at a server shows the server\'s name');
    $check($records->action('proxy')->appliesTo(['type' => 'A', 'proxied' => false]) && ! $records->action('proxy')->appliesTo(['type' => 'TXT', 'proxied' => false]) && $records->action('unproxy')->appliesTo(['proxied' => true]), 'proxy on for A/AAAA/CNAME off the proxy; proxy off when on');
    $rules = $cf->section('access_rules');
    $rows = ($rules->list)($api, ['zone' => 'z1']);
    ($rules->toolbarAction('create')->run)($api, [], ['mode' => 'whitelist', 'target' => 'ip', 'value' => 'mozart', 'notes' => null], ['zone' => 'z1']);
    $body = $api->sent('POST', '/zones/z1/firewall/access_rules/rules')['json'] ?? [];
    $check($render($rules, $rows)[0]['configuration.value'] === '192.0.2.66' && ($body['configuration'] ?? null) === ['target' => 'ip', 'value' => '203.0.113.10'] && ! isset($body['notes']), 'IP access rules: listed; "allow mozart" sends its address');
    $check(in_array('access-rules', array_map(fn ($a) => $a->key, $zones->actions), true) && count($cf->instructions()) >= 4, 'zones open their IP access rules; the token\'s permissions are spelled out');

    echo "--- DigitalOcean, Hetzner, AWS: records to a server\n";
    $drop = Connectors::make('digitalocean')->section('droplets');
    $ec2 = Connectors::make('aws')->section('ec2');
    $check($render($drop, [['name' => 'web-1', 'networks' => ['v4' => [['type' => 'private', 'ip_address' => '10.0.0.2'], ['type' => 'public', 'ip_address' => '203.0.113.10']]]]])[0]['ip'] === '203.0.113.10 → mozart'
        && $render($ec2, [['instanceId' => 'i-1', 'ipAddress' => '2001:db8::7']])[0]['ipAddress'] === '2001:db8::7 → bach', 'a Droplet or an EC2 instance says which server of the panel it is');
    $do = Connectors::make('digitalocean')->section('records');
    $api = new FakeApi(['POST /domains/example.com/records' => ['domain_record' => ['id' => 9]]]);
    ($do->toolbarAction('create')->run)($api, [], ['type' => 'A', 'name' => '@', 'data' => 'mozart', 'ttl' => 1800, 'priority' => null, 'port' => null, 'weight' => null], ['domain' => 'example.com']);
    $check((($api->sent('POST', '/domains/example.com/records')['json'] ?? [])['data'] ?? null) === '203.0.113.10', 'DigitalOcean: A record to mozart');
    $hz = Connectors::make('hetzner')->section('rrsets');
    $api = new FakeApi();
    ($hz->toolbarAction('create')->run)($api, [], ['type' => 'AAAA', 'name' => 'www', 'values' => "bach\n2001:db8::9", 'ttl' => null], ['zone' => '42']);
    $check((($api->sent('POST', '/zones/42/rrsets')['json'] ?? [])['records'] ?? null) === [['value' => '2001:db8::7'], ['value' => '2001:db8::9']], 'Hetzner: an AAAA set, a server and an address');

    echo "--- Google Cloud\n";
    $g = Connectors::make('gcp');
    $api = new FakeApi([
        'GET /aggregated/instances' => ['items' => ['zones/europe-west8-a' => ['instances' => [['name' => 'web-1', 'status' => 'RUNNING', 'zone' => 'https://x/zones/europe-west8-a', 'machineType' => 'https://x/machineTypes/e2-small',
            'networkInterfaces' => [['networkIP' => '10.0.0.2', 'accessConfigs' => [['natIP' => '203.0.113.10']]]], 'creationTimestamp' => '2026-11-01T10:00:00Z']]], 'zones/us-east1-b' => ['warning' => ['code' => 'NO_RESULTS_ON_PAGE']]], 'nextPageToken' => 'p2'],
        'GET /global/firewalls' => ['items' => [['name' => 'allow-web', 'direction' => 'INGRESS', 'allowed' => [['IPProtocol' => 'tcp', 'ports' => ['80', '443']]], 'sourceRanges' => ['0.0.0.0/0']]]],
        'GET /dns/v1/projects/acme-prod/managedZones/example-com/rrsets' => ['rrsets' => [['name' => 'example.com.', 'type' => 'A', 'ttl' => 300, 'rrdatas' => ['203.0.113.10']]]],
    ], ['project' => 'acme-prod']);
    $inst = $g->section('instances');
    $pages = 0;
    $api->answers['GET /aggregated/instances'] = function (array $o) use (&$pages) {
        $pages++;
        return $pages === 1
            ? ['items' => ['zones/europe-west8-a' => ['instances' => [['name' => 'web-1', 'status' => 'RUNNING', 'zone' => 'https://x/zones/europe-west8-a', 'machineType' => 'https://x/machineTypes/e2-small', 'networkInterfaces' => [['networkIP' => '10.0.0.2', 'accessConfigs' => [['natIP' => '203.0.113.10']]]]]]], 'zones/us-east1-b' => ['warning' => []]], 'nextPageToken' => 'p2']
            : ['items' => ['zones/us-east1-b' => ['instances' => [['name' => 'db-1', 'status' => 'TERMINATED', 'zone' => 'https://x/zones/us-east1-b']]]]];
    };
    $rows = ($inst->list)($api, []);
    $cells = $render($inst, $rows);
    $check(count($rows) === 2 && $cells[0]['zone'] === 'europe-west8-a' && $cells[0]['machineType'] === 'e2-small' && $cells[0]['external'] === '203.0.113.10 → mozart' && ($api->calls[1][2]['query']['pageToken'] ?? null) === 'p2', 'instances: every zone of the aggregated list, every page; the address says which server');
    $check($inst->action('start')->appliesTo(['status' => 'TERMINATED']) && ! $inst->action('start')->appliesTo(['status' => 'RUNNING']) && $inst->action('stop')->appliesTo(['status' => 'RUNNING']), 'start a stopped instance, stop a running one');
    ($inst->action('stop')->run)($api, $rows[0], [], []);
    $check($api->sent('POST', '/zones/europe-west8-a/instances/web-1/stop') !== null, 'stop: POST on the instance in its zone');
    ($inst->toolbarAction('create')->run)($api, [], ['name' => 'web-2', 'zone' => 'europe-west8-a', 'machine' => 'e2-small', 'image' => 'projects/ubuntu-os-cloud/global/images/family/ubuntu-2404-lts-amd64', 'disk' => 30, 'ssh_key' => 'ssh-ed25519 AAAAC3Nz andrea@mac', 'web' => true], []);
    $body = $api->sent('POST', '/zones/europe-west8-a/instances')['json'] ?? [];
    $check(($body['machineType'] ?? '') === 'zones/europe-west8-a/machineTypes/e2-small' && ($body['tags']['items'] ?? []) === ['http-server', 'https-server'] && ($body['metadata']['items'][0]['value'] ?? '') === 'andrea:ssh-ed25519 AAAAC3Nz andrea@mac', 'new instance: Ubuntu 24.04, web tags, the SSH key for its user');
    $fw = $g->section('firewalls');
    $check($render($fw, ($fw->list)($api, []))[0]['allowed'] === 'tcp:80,443', 'firewall rules: what each allows');
    ($fw->toolbarAction('create')->run)($api, [], ['name' => 'ssh', 'ports' => '22, 53/udp, icmp', 'sources' => '198.51.100.0/24', 'tags' => null, 'network' => 'default'], []);
    $body = $api->sent('POST', '/global/firewalls')['json'] ?? [];
    $check(($body['allowed'] ?? null) === [['IPProtocol' => 'tcp', 'ports' => ['22']], ['IPProtocol' => 'udp', 'ports' => ['53']], ['IPProtocol' => 'icmp']] && ($body['sourceRanges'] ?? null) === ['198.51.100.0/24'] && ! isset($body['targetTags']), 'a new rule from "22, 53/udp, icmp"');
    $dns = $g->section('dns_records');
    $rows = ($dns->list)($api, ['zone' => 'example-com', 'zone_name' => 'example.com']);
    ($dns->toolbarAction('create')->run)($api, [], ['type' => 'A', 'name' => 'api', 'values' => 'mozart', 'ttl' => 300], ['zone' => 'example-com', 'zone_name' => 'example.com']);
    $body = $api->sent('POST', '/dns/v1/projects/acme-prod/managedZones/example-com/rrsets')['json'] ?? [];
    $check($render($dns, $rows)[0]['rrdatas'] === '203.0.113.10 → mozart' && ($body['name'] ?? '') === 'api.example.com.' && ($body['rrdatas'] ?? null) === ['203.0.113.10'], 'Cloud DNS: records listed; "api → mozart" as api.example.com.');
    $check(str_contains((string) $threw(fn () => $g->client(['key' => 'not json'])), 'not JSON') && str_contains((string) $threw(fn () => $g->client(['key' => '{"client_email":"a@b"}', 'project' => 'Bad_Project'])), 'project ID'), 'a key that is not JSON, or a bad project, is said plainly');

    echo "--- Azure\n";
    $az = Connectors::make('azure');
    $vmId = '/subscriptions/11111111-2222-3333-4444-555555555555/resourceGroups/web/providers/Microsoft.Compute/virtualMachines/vm1';
    $api = new FakeApi([
        'GET /providers/Microsoft.Compute/virtualMachines' => ['value' => [['id' => $vmId, 'name' => 'vm1', 'location' => 'italynorth', 'properties' => ['hardwareProfile' => ['vmSize' => 'Standard_B2s'], 'storageProfile' => ['osDisk' => ['osType' => 'Linux']], 'instanceView' => ['statuses' => [['code' => 'ProvisioningState/succeeded'], ['code' => 'PowerState/running']]]]]],
            'nextLink' => 'https://management.azure.com/subscriptions/x/providers/Microsoft.Compute/virtualMachines?page=2'],
        'GET /subscriptions/x/providers/Microsoft.Compute/virtualMachines' => ['value' => [['id' => $vmId.'2', 'name' => 'vm2', 'properties' => ['instanceView' => ['statuses' => [['code' => 'PowerState/deallocated']]]]]]],
        'GET /subscriptions/z/resourceGroups/dns/providers/Microsoft.Network/dnszones/example.com/recordsets' => ['value' => [
            ['id' => '/subscriptions/z/resourceGroups/dns/providers/Microsoft.Network/dnszones/example.com/A/@', 'name' => '@', 'type' => 'Microsoft.Network/dnszones/A', 'properties' => ['TTL' => 3600, 'ARecords' => [['ipv4Address' => '203.0.113.10']]]],
            ['id' => '/subscriptions/z/resourceGroups/dns/providers/Microsoft.Network/dnszones/example.com/MX/@', 'name' => '@', 'type' => 'Microsoft.Network/dnszones/MX', 'properties' => ['TTL' => 3600, 'MXRecords' => [['preference' => 10, 'exchange' => 'mx.example.com']]]],
        ]],
    ], ['subscription' => '11111111-2222-3333-4444-555555555555']);
    $vms = $az->section('vms');
    $rows = ($vms->list)($api, []);
    $cells = $render($vms, $rows);
    $check(count($rows) === 2 && $cells[0]['power'] === 'running' && $cells[0]['group'] === 'web' && $cells[1]['power'] === 'deallocated', 'virtual machines: every page (nextLink), power state, resource group');
    ($vms->action('deallocate')->run)($api, $rows[0], [], []);
    $check($api->sent('POST', $vmId.'/deallocate') !== null && $vms->action('start')->appliesTo($rows[1]) && ! $vms->action('start')->appliesTo($rows[0]), 'deallocate a running VM by its id; start a deallocated one');
    $rec = $az->section('dns_records');
    $scope = ['zone' => '/subscriptions/z/resourceGroups/dns/providers/Microsoft.Network/dnszones/example.com', 'zone_name' => 'example.com'];
    $rows = ($rec->list)($api, $scope);
    $cells = $render($rec, $rows);
    $check($cells[0]['type'] === 'A' && $cells[0]['values'] === '203.0.113.10 → mozart' && $cells[1]['values'] === '10 mx.example.com', 'DNS records of every type, values read per type');
    ($rec->toolbarAction('create')->run)($api, [], ['type' => 'A', 'name' => 'api', 'values' => "mozart\n198.51.100.4", 'ttl' => 300], $scope);
    $put = null; foreach ($api->calls as $c) { if ($c[0] === 'PUT') { $put = $c; } }
    $check($put !== null && str_ends_with($put[1], '/dnszones/example.com/A/api') && ($put[2]['json']['properties'] ?? null) === ['TTL' => 300, 'ARecords' => [['ipv4Address' => '203.0.113.10'], ['ipv4Address' => '198.51.100.4']]], 'a new A record set: PUT on /A/api with each address');
    $check(str_contains((string) $threw(fn () => ($rec->toolbarAction('create')->run)($api, [], ['type' => 'MX', 'name' => '@', 'values' => 'mail.example.com', 'ttl' => 300], $scope)), '10 mail.example.com'), 'an MX without its preference is refused, with the shape');
    $check(str_contains((string) $threw(fn () => $az->client(['subscription_id' => 'nope'])), 'GUID'), 'a subscription that is not a GUID is said plainly');

    echo "--- OVHcloud\n";
    $ovh = Connectors::make('ovh');
    $api = new FakeApi([
        'GET /vps' => ['vps-1a2b3c.vps.ovh.net'],
        'GET /vps/vps-1a2b3c.vps.ovh.net' => ['displayName' => 'mozart', 'state' => 'running', 'model' => ['name' => 'vps-value-1-2-40'], 'zone' => 'Region OpenStack: os-gra7'],
        'GET /vps/vps-1a2b3c.vps.ovh.net/ips' => ['203.0.113.10', '2001:db8::10'],
        'GET /domain/zone/example.com/record' => [101, 102],
        'GET /domain/zone/example.com/record/101' => ['id' => 101, 'fieldType' => 'A', 'subDomain' => '', 'target' => '203.0.113.10', 'ttl' => 0],
        'GET /domain/zone/example.com/record/102' => ['id' => 102, 'fieldType' => 'CNAME', 'subDomain' => 'www', 'target' => 'example.com.', 'ttl' => 3600],
    ]);
    $vps = $ovh->section('vps');
    $rows = ($vps->list)($api, []);
    $cells = $render($vps, $rows);
    $check($cells[0]['_name'] === 'vps-1a2b3c.vps.ovh.net' && $cells[0]['displayName'] === 'mozart' && $cells[0]['_ips'] === '203.0.113.10 → mozart, 2001:db8::10', 'VPS: names, then each one asked about, with its addresses');
    ($vps->action('reboot')->run)($api, $rows[0], [], []);
    $check($api->sent('POST', '/vps/vps-1a2b3c.vps.ovh.net/reboot') !== null, 'reboot a VPS');
    $rec = $ovh->section('records');
    $rows = ($rec->list)($api, ['zone' => 'example.com', 'zone_name' => 'example.com']);
    $cells = $render($rec, $rows);
    $check($cells[0]['subDomain'] === '@' && $cells[0]['target'] === '203.0.113.10 → mozart' && $cells[1]['ttl'] === '3600', 'records: each asked about; @ for the zone');
    ($rec->toolbarAction('create')->run)($api, [], ['fieldType' => 'A', 'subDomain' => 'api', 'target' => 'mozart', 'ttl' => null], ['zone' => 'example.com']);
    $check((($api->sent('POST', '/domain/zone/example.com/record')['json'] ?? [])['target'] ?? null) === '203.0.113.10' && $api->sent('POST', '/domain/zone/example.com/refresh') !== null, 'a new record to mozart, and the zone published');
    $sig = CipiGui\Infrastructure\OvhClient::signature('AS', 'CK', 'get', 'https://eu.api.ovh.com/1.0/me', '', '1700000000');
    $check($sig === '$1$'.sha1('AS+CK+GET+https://eu.api.ovh.com/1.0/me++1700000000'), 'the signature: $1$ and SHA-1 of secret+consumer+METHOD+URL+body+time');

    echo "--- Vultr, Akamai, Scaleway\n";
    $v = Connectors::make('vultr')->section('instances');
    $api = new FakeApi(['GET /instances' => function (array $o) {
        return ($o['query']['cursor'] ?? null) === null
            ? ['instances' => [['id' => 'i1', 'label' => 'mozart', 'main_ip' => '203.0.113.10', 'power_status' => 'running', 'status' => 'active', 'plan' => 'vc2-1c-1gb', 'region' => 'fra', 'os' => 'Ubuntu 24.04 LTS x64']], 'meta' => ['links' => ['next' => 'bmV4dA==']]]
            : ['instances' => [['id' => 'i2', 'label' => 'bach', 'power_status' => 'stopped']], 'meta' => ['links' => ['next' => '']]];
    }, 'POST /instances' => ['instance' => ['id' => 'i3', 'default_password' => 'S3cret!']]]);
    $rows = ($v->list)($api, []);
    $check(count($rows) === 2 && $render($v, $rows)[0]['main_ip'] === '203.0.113.10 → mozart' && $v->action('start')->appliesTo($rows[1]), 'Vultr instances: every page by cursor, start a stopped one');
    $out = ($v->toolbarAction('create')->run)($api, [], ['label' => 'web', 'region' => 'fra', 'plan' => 'vc2-1c-1gb', 'os_id' => '2284', 'sshkey_id' => [], 'backups' => false], []);
    $body = $api->sent('POST', '/instances')['json'] ?? [];
    $check($out instanceof CipiGui\Infrastructure\Outcome && $out->data === 'S3cret!' && ($body['os_id'] ?? null) === 2284 && ($body['backups'] ?? '') === 'disabled' && ! isset($body['sshkey_id']), 'a new instance: its root password shown once, no empty key list');
    $l = Connectors::make('linode')->section('linodes');
    $api = new FakeApi(['GET /linode/instances' => fn (array $o) => ($o['query']['page'] ?? 1) == 1
        ? ['data' => [['id' => 1, 'label' => 'mozart', 'status' => 'running', 'ipv4' => ['203.0.113.10', '192.168.1.2']]], 'page' => 1, 'pages' => 2]
        : ['data' => [['id' => 2, 'label' => 'bach', 'status' => 'offline', 'ipv4' => []]], 'page' => 2, 'pages' => 2]]);
    $rows = ($l->list)($api, []);
    $check(count($rows) === 2 && $render($l, $rows)[0]['ipv4'] === '203.0.113.10 → mozart, 192.168.1.2' && $l->action('boot')->appliesTo($rows[1]), 'Linodes: every page, addresses, boot one that is off');
    $s = Connectors::make('scaleway')->section('servers');
    $api = new FakeApi(['GET /instance/v1/zones/nl-ams-1/servers' => ['servers' => [['id' => 's1', 'name' => 'mozart', 'state' => 'running', 'commercial_type' => 'DEV1-S', 'public_ip' => ['address' => '203.0.113.10']]], 'total_count' => 1]]);
    $rows = ($s->list)($api, ['zone' => 'nl-ams-1']);
    ($s->action('reboot')->run)($api, $rows[0], [], ['zone' => 'nl-ams-1']);
    $check(count($rows) === 1 && $render($s, $rows)[0]['public_ip'] === '203.0.113.10 → mozart' && (($api->sent('POST', '/instance/v1/zones/nl-ams-1/servers/s1/action')['json'] ?? []) === ['action' => 'reboot']), 'Scaleway: the zone of the filter, one action endpoint');
    $sr = Connectors::make('scaleway')->section('records');
    $api = new FakeApi();
    ($sr->toolbarAction('create')->run)($api, [], ['type' => 'A', 'name' => 'api', 'data' => 'mozart', 'ttl' => 3600, 'priority' => null], ['dns_zone' => 'example.com']);
    $check((($api->sent('PATCH', '/domain/v2beta1/dns-zones/example.com/records')['json'] ?? [])['changes'][0]['add']['records'][0] ?? null) === ['name' => 'api', 'type' => 'A', 'data' => '203.0.113.10', 'ttl' => 3600], 'Scaleway DNS: a record added as a change');


    echo "--- S3: presigned links, folders, keys for one bucket\n";
    $signer = new CipiGui\Infrastructure\Aws\Signer('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY');
    $url = $signer->presign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', 'us-east-1', 86400, 's3', new DateTimeImmutable('2013-05-24T00:00:00Z'));
    $check(str_ends_with($url, 'X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404'), 'a presigned link signs as AWS\'s own example');
    final class FakeS3 extends CipiGui\Infrastructure\S3\S3Client
    {
        public array $calls = [];
        public array $answers = [];
        protected function call(string $method, string $url, string $body = '', array $headers = [], ?string $region = null): string
        {
            $this->calls[] = [$method, $url, $body, $headers, $region];
            foreach ($this->answers as $pattern => $answer) { if (preg_match($pattern, $method.' '.$url)) { return $answer; } }
            return '';
        }
    }
    $s3 = new FakeS3($signer, 'https://s3.{region}.wasabisys.com', 'eu-central-1');
    $check($s3->url('media', 'a b/c.jpg', [], 'eu-west-1') === 'https://media.s3.eu-west-1.wasabisys.com/a%20b/c.jpg' && $s3->url('my.site', 'x') === 'https://s3.eu-central-1.wasabisys.com/my.site/x', 'objects by virtual host, path style for a bucket with a dot');
    $s3->answers = ['#GET .*list-type=2#' => '<ListBucketResult><Prefix>docs/</Prefix><CommonPrefixes><Prefix>docs/old/</Prefix></CommonPrefixes><Contents><Key>docs/</Key><Size>0</Size></Contents><Contents><Key>docs/a.pdf</Key><Size>2048</Size><LastModified>2026-11-01T10:00:00Z</LastModified></Contents><IsTruncated>false</IsTruncated></ListBucketResult>'];
    $wasabi = Connectors::make('wasabi');
    $objects = $wasabi->section('objects');
    $account = new CipiGui\Infrastructure\S3\S3Account($s3);
    $rows = ($objects->list)($account, ['bucket' => 'media', 'region' => 'eu-west-1', 'prefix' => 'docs/']);
    $check(array_column($rows, 'kind') === ['up', 'folder', 'file'] && $rows[1]['name'] === 'old/' && $rows[2]['size'] === '2.0 KB' && str_contains($s3->calls[0][1], 'prefix=docs%2F'), 'a folder: up, its subfolders, its files (not itself), sizes readable');
    $open = $objects->action('open');
    $check($open->appliesTo($rows[1]) && ! $open->appliesTo($rows[2]) && ($open->scope)($rows[1]) === ['bucket' => 'media', 'region' => 'eu-west-1', 'prefix' => 'docs/old/'] && ($open->scope)($rows[0])['prefix'] === '', 'opening a folder keeps bucket and region; .. goes up');
    $link = ($objects->action('link')->run)($account, $rows[2], ['hours' => 2], ['bucket' => 'media', 'region' => 'eu-west-1']);
    $check(str_contains((string) $link->data, 'media.s3.eu-west-1.wasabisys.com/docs/a.pdf?') && str_contains((string) $link->data, 'X-Amz-Expires=7200'), 'a link that expires in two hours');
    $browse = Connectors::make('digitalocean')->section('spaces')->action('browse');
    $check(($browse->scope)(['Name' => 'uploads', '_region' => 'nyc3']) === ['bucket' => 'uploads', 'region' => 'nyc3', 'prefix' => ''] && (Connectors::make('aws')->section('s3')->action('browse')->scope)(['Name' => 'logs', 'BucketRegion' => 'eu-south-1'])['region'] === 'eu-south-1', 'browsing a bucket goes to its region: a Space in nyc3, an S3 bucket in Milan');
    $check(CipiGui\Infrastructure\S3\S3Sections::origins("https://app.example.com/\n*") === ['https://app.example.com', '*'] && str_contains((string) $threw(fn () => CipiGui\Infrastructure\S3\S3Sections::origins('app.example.com/path')), 'not a site'), 'CORS sites: origins only, with https://');
    final class FakeIam extends CipiGui\Infrastructure\S3\Iam
    {
        public array $bodies = [];
        protected function call(string $body): string
        {
            parse_str($body, $q);
            $this->bodies[] = $q;
            return match ($q['Action']) {
                'CreateAccessKey' => '<CreateAccessKeyResponse><CreateAccessKeyResult><AccessKey><AccessKeyId>AKIAAPP</AccessKeyId><SecretAccessKey>s3cr3t</SecretAccessKey></AccessKey></CreateAccessKeyResult></CreateAccessKeyResponse>',
                default => '<R/>',
            };
        }
    }
    $iam = new FakeIam($signer);
    $key = $iam->createAppKey('media');
    $policy = json_decode((string) ($iam->bodies[1]['PolicyDocument'] ?? ''), true);
    $check($iam->bodies[0]['Action'] === 'CreateUser' && $iam->bodies[0]['Path'] === '/cipi/' && str_starts_with($key['user'], 'cipi-media-') && $key['access_key_id'] === 'AKIAAPP' && $key['secret_access_key'] === 's3cr3t', 'a key for an app: an IAM user under /cipi/, then its key');
    $check(($policy['Statement'][0]['Resource'] ?? '') === 'arn:aws:s3:::media' && ($policy['Statement'][1]['Resource'] ?? '') === 'arn:aws:s3:::media/*' && in_array('s3:PutObject', $policy['Statement'][1]['Action'], true) && ! in_array('s3:PutObject', CipiGui\Infrastructure\S3\Iam::policy('media', true)['Statement'][1]['Action'], true), 'its policy opens that bucket only; read-only cannot write');
    $check(CipiGui\Infrastructure\S3\Iam::bucketOf('cipi-media-abc123') === 'media' && str_contains((string) $threw(fn () => $iam->deleteUser('admin')), 'Only the keys the panel made'), 'the panel removes only the users it made');

    echo "--- DNS published on Cloudflare\n";
    $cfApi = new FakeApi([
        'GET /zones' => fn ($o) => ($o['query']['name'] ?? '') === 'example.com' ? ['result' => [['id' => 'z1', 'name' => 'example.com', 'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com']]]] : ['result' => []],
        'GET /zones/z1/dns_records' => fn ($o) => match ($o['query']['name'] ?? '') {
            'pm-bounces.example.com' => ['result' => [['type' => 'CNAME', 'content' => 'other.example.net']]],
            '20261101pm._domainkey.example.com' => ['result' => [['type' => 'TXT', 'content' => '"k=rsa; p=MIGf"']]],
            default => ['result' => []],
        },
    ]);
    CipiGui\Infrastructure\DnsPublisher::$clientsUsing = fn () => [$cfApi];
    $summary = CipiGui\Infrastructure\DnsPublisher::publish('mg.example.com', [
        ['type' => 'TXT', 'name' => '20261101pm._domainkey.example.com', 'content' => 'k=rsa; p=MIGf'],
        ['type' => 'CNAME', 'name' => 'pm-bounces.example.com', 'content' => 'pm.mtasv.net'],
        ['type' => 'MX', 'name' => 'mg.example.com', 'content' => 'mxa.eu.mailgun.org', 'priority' => 10],
    ]);
    $created = $cfApi->sent('POST', '/zones/z1/dns_records')['json'] ?? [];
    $check(str_contains($summary, '= TXT') && str_contains($summary, '! CNAME pm-bounces.example.com') && str_contains($summary, '+ MX mg.example.com') && $created === ['type' => 'MX', 'name' => 'mg.example.com', 'content' => 'mxa.eu.mailgun.org', 'ttl' => 1, 'priority' => 10], 'found the zone of a subdomain; kept a record already there, reported a CNAME pointing elsewhere, added the MX');
    $check(CipiGui\Infrastructure\DnsPublisher::cloudflareNameservers('example.com') === ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'] && CipiGui\Infrastructure\DnsPublisher::cloudflareNameservers('other.org') === null, 'a registrar can point a domain to its Cloudflare zone\'s nameservers');
    $check(str_contains((string) $threw(fn () => CipiGui\Infrastructure\DnsPublisher::publish('other.org', [['type' => 'TXT', 'name' => 'other.org', 'content' => 'x']])), "Add these records at its DNS:\nTXT"), 'no zone of the panel: the records to add by hand');
    $check(CipiGui\Infrastructure\DnsPublisher::fqdn('send', 'example.com') === 'send.example.com' && CipiGui\Infrastructure\DnsPublisher::fqdn('@', 'example.com') === 'example.com' && CipiGui\Infrastructure\DnsPublisher::fqdn('a.example.com.', 'example.com') === 'a.example.com', 'record names made whole');

    echo "--- Email: Postmark, Mailgun, Resend, SES\n";
    $pm = CipiGui\Infrastructure\Connectors\Postmark::records(['Name' => 'example.com', 'DKIMHost' => 'old._domainkey', 'DKIMTextValue' => 'k=rsa; p=OLD', 'DKIMPendingHost' => '20261101pm._domainkey', 'DKIMPendingTextValue' => 'k=rsa; p=NEW', 'ReturnPathDomain' => 'pm-bounces.example.com', 'ReturnPathDomainCNAMEValue' => 'pm.mtasv.net']);
    $check($pm === [['type' => 'TXT', 'name' => '20261101pm._domainkey.example.com', 'content' => 'k=rsa; p=NEW'], ['type' => 'CNAME', 'name' => 'pm-bounces.example.com', 'content' => 'pm.mtasv.net']], 'Postmark: the pending DKIM while it rotates, and the Return-Path');
    $mg = CipiGui\Infrastructure\Connectors\Mailgun::records('mg.example.com', ['sending_dns_records' => [['record_type' => 'TXT', 'name' => 'mg.example.com', 'value' => 'v=spf1 include:mailgun.org ~all'], ['record_type' => 'CNAME', 'name' => 'email.mg.example.com', 'value' => 'eu.mailgun.org']], 'receiving_dns_records' => [['record_type' => 'MX', 'value' => 'mxa.eu.mailgun.org', 'priority' => '10']]]);
    $check(count($mg) === 3 && $mg[2] === ['type' => 'MX', 'name' => 'mg.example.com', 'content' => 'mxa.eu.mailgun.org', 'priority' => 10], 'Mailgun: SPF, tracking CNAME and the MX that receive');
    $api = new FakeApi(['POST /v4/domains' => ['sending_dns_records' => []]]);
    ($b = Connectors::make('mailgun')->section('domains')->toolbarAction('create')->run)($api, [], ['name' => 'MG.Example.com', 'dkim_key_size' => '2048'], []);
    $check(($api->sent('POST', '/v4/domains')['form_params'] ?? null) === ['name' => 'mg.example.com', 'dkim_key_size' => '2048'], 'Mailgun: a new domain is a form, as its API wants');
    $rs = CipiGui\Infrastructure\Connectors\Resend::records(['name' => 'example.com', 'records' => [['record' => 'SPF', 'name' => 'send', 'type' => 'MX', 'value' => 'feedback-smtp.eu-west-1.amazonses.com', 'priority' => 10], ['record' => 'DKIM', 'name' => 'resend._domainkey', 'type' => 'TXT', 'value' => 'p=MIGf']]]);
    $check($rs[0]['name'] === 'send.example.com' && $rs[0]['priority'] === 10 && $rs[1]['name'] === 'resend._domainkey.example.com', 'Resend: its relative names made whole');
    $ses = (new ReflectionMethod(CipiGui\Infrastructure\Connectors\Aws::class, 'sesRecords'))->invoke(null, 'example.com', ['DkimAttributes' => ['Tokens' => ['t1', 't2', 't3']]]);
    $check(count($ses) === 3 && $ses[0] === ['type' => 'CNAME', 'name' => 't1._domainkey.example.com', 'content' => 't1.dkim.amazonses.com'], 'SES: three DKIM CNAMEs');
    $aws = Connectors::make('aws');
    $check($aws->section('ses')->action('publish-dns')->appliesTo(['IdentityType' => 'DOMAIN']) && ! $aws->section('ses')->action('publish-dns')->appliesTo(['IdentityType' => 'EMAIL_ADDRESS']), 'SES: a domain publishes its DNS, an address does not');

    echo "--- Storage: Johnny, Backblaze B2\n";
    $johnny = Connectors::make('johnny');
    $api = new FakeApi(['POST /buckets/provision' => ['bucket' => 'b-1', 'region' => 'garage', 'endpoint' => 'https://s3.example.com', 'path_style' => true, 'credentials' => ['access_key_id' => 'GK1', 'secret_access_key' => 'sec'], 'env' => ['AWS_ACCESS_KEY_ID' => 'GK1', 'AWS_SECRET_ACCESS_KEY' => 'sec', 'AWS_DEFAULT_REGION' => 'garage', 'AWS_ENDPOINT_URL' => 'https://s3.example.com']]]);
    $out = ($johnny->section('buckets')->toolbarAction('provision')->run)($api, [], ['bucket' => null, 'scope' => 'read-write'], []);
    $check(($api->sent('POST', '/buckets/provision')['json'] ?? null) === ['scope' => 'read-write'] && str_contains((string) $out->data, 'AWS_BUCKET=b-1') && str_contains((string) $out->data, 'AWS_ENDPOINT=https://s3.example.com') && str_contains((string) $out->data, 'AWS_USE_PATH_STYLE_ENDPOINT=true'), 'Johnny: bucket and key in one call, the .env Laravel reads');
    ($johnny->section('buckets')->action('quotas')->run)($api, ['name' => 'my.bucket'], ['max_gb' => 10, 'max_objects' => null], []);
    $check(($api->sent('PUT', '/buckets/my.bucket/quotas')['json'] ?? null) === ['max_size' => 10737418240, 'max_objects' => null], 'Johnny: 10 GB of quota are bytes; no object limit is null');
    $b2 = Connectors::make('backblaze');
    $api = new FakeApi(['POST /b2_create_key' => ['applicationKeyId' => 'k1', 'applicationKey' => 'K001sec'], 'POST /b2_list_file_names' => ['files' => [['fileName' => 'img/', 'action' => 'folder'], ['fileName' => 'img/a.png', 'action' => 'upload', 'contentLength' => 10, 'uploadTimestamp' => 1764000000000, 'fileId' => 'f1']]]], ['account' => 'acc', 's3' => 'https://s3.eu-central-003.backblazeb2.com', 'download' => 'https://f003.backblazeb2.com']);
    $out = ($b2->section('buckets')->action('app-key')->run)($api, ['bucketId' => 'bk1', 'bucketName' => 'media'], ['read_only' => true], []);
    $sent = $api->sent('POST', '/b2_create_key')['json'] ?? [];
    $check(($sent['bucketId'] ?? '') === 'bk1' && ! in_array('writeFiles', $sent['capabilities'] ?? [], true) && str_contains((string) $out->data, 'AWS_DEFAULT_REGION=eu-central-003') && str_contains((string) $out->data, 'AWS_ENDPOINT=https://s3.eu-central-003.backblazeb2.com'), 'B2: a read-only key for one bucket, with its S3 endpoint and region');
    $files = ($b2->section('files')->list)($api, ['bucket' => 'media', 'bucketId' => 'bk1', 'prefix' => '']);
    $check(array_column($files, 'kind') === ['folder', 'file'] && $files[1]['id'] === 'f1', 'B2: files folder by folder');

    echo "--- Monitoring and security: Boogle, Sentry, Datadog, Aikido, Hackly\n";
    $api = new FakeApi(['GET /projects/p1' => ['id' => 'p1', 'title' => 'Shop', 'key' => 'PK', 'api_token' => 'TOK']]);
    $api = new class('https://boogle.example.com/api/admin') extends CipiGui\Infrastructure\ApiClient { public function send(string $m, string $p, array $o = []): array { return ['id' => 'p1', 'title' => 'Shop', 'key' => 'PK', 'api_token' => 'TOK']; } };
    $out = (Connectors::make('boogle')->section('projects')->action('env')->run)($api, ['id' => 'p1', 'title' => 'Shop'], [], []);
    $check(str_contains((string) $out->data, 'BOOGLE_KEY=TOK') && str_contains((string) $out->data, 'BOOGLE_PROJECT_KEY=PK') && str_contains((string) $out->data, 'BOOGLE_SERVER=https://boogle.example.com/api/log'), 'Boogle: the client\'s .env, the server at /api/log');
    $api = new FakeApi(['GET /projects' => ['data' => [['id' => 'p1', 'title' => 'Shop', 'key' => 'PK', 'api_token' => 'TOK']], 'next_page_url' => null]]);
    $rows = (Connectors::make('boogle')->section('projects')->list)($api, []);
    $check(! isset($rows[0]['key']) && ! isset($rows[0]['api_token']), 'Boogle: the project keys stay out of the list');
    $api = new FakeApi([], ['org' => 'acme']);
    (Connectors::make('sentry')->section('issues')->action('resolve-next')->run)($api, ['id' => '42', 'status' => 'unresolved'], [], []);
    $check(($api->sent('PUT', '/organizations/acme/issues/42/')['json'] ?? null) === ['status' => 'resolved', 'statusDetails' => ['inNextRelease' => true]], 'Sentry: resolved in the next release');
    $api = new FakeApi([], ['web' => 'https://app.datadoghq.eu']);
    (Connectors::make('datadog')->section('monitors')->action('mute')->run)($api, ['id' => 7], ['for' => '3600', 'message' => null], []);
    $dt = $api->sent('POST', '/api/v2/downtime')['json']['data']['attributes'] ?? [];
    $check(($dt['monitor_identifier'] ?? null) === ['monitor_id' => 7] && isset($dt['schedule']['end']) && ! isset($dt['schedule']['start']), 'Datadog: muted for an hour with a downtime of that monitor');
    $pagesAsked = 0;
    $api = new FakeApi(['GET /open-issue-groups' => function ($o) use (&$pagesAsked) { $pagesAsked++; return $o['query']['page'] === 0 ? array_fill(0, 100, ['id' => 1, 'severity' => 'high']) : [['id' => 2, 'severity' => 'low']]; }]);
    $rows = (Connectors::make('aikido')->section('issues')->list)($api, ['status' => 'snoozed']);
    $check(count($rows) === 101 && $pagesAsked === 2 && $rows[0]['_status'] === 'snoozed' && Connectors::make('aikido')->section('issues')->action('unsnooze')->appliesTo($rows[0]) && ! Connectors::make('aikido')->section('issues')->action('snooze')->appliesTo($rows[0]), 'Aikido: every page until a short one; snoozed issues wake up, open ones snooze');
    $check(CipiGui\Infrastructure\Connectors\Hackly::verification(['domain' => 'example.com', 'verification' => ['host' => '_hackly.example.com', 'value' => 'hk-verify=abc']]) === [['type' => 'TXT', 'name' => '_hackly.example.com', 'content' => 'hk-verify=abc']], 'Hackly: the ownership TXT of a target');

    echo "--- Registrars and VPS: Porkbun, Gandi, Namecheap, Contabo, UpCloud\n";
    $api = new FakeApi(['POST /dns/create/example.com' => ['status' => 'SUCCESS', 'id' => 5], 'POST /dns/retrieve/example.com' => ['status' => 'ERROR', 'message' => 'Domain is not opted in to API access.']], ['apikey' => 'pk1_a', 'secretapikey' => 'sk1_b']);
    $pb = Connectors::make('porkbun')->section('records');
    ($pb->toolbarAction('create')->run)($api, [], ['type' => 'A', 'name' => '@', 'content' => 'mozart', 'ttl' => 300, 'prio' => null], ['domain' => 'example.com']);
    $check(($api->sent('POST', '/dns/create/example.com')['json'] ?? null) === ['apikey' => 'pk1_a', 'secretapikey' => 'sk1_b', 'type' => 'A', 'name' => '', 'content' => '203.0.113.10', 'ttl' => '600'], 'Porkbun: keys in the body, @ is the domain, mozart is its address, TTL 600 at least');
    $check(str_contains((string) $threw(fn () => ($pb->list)($api, ['domain' => 'example.com'])), 'not opted in'), 'Porkbun: a refusal in the answer is said');
    $api = new FakeApi();
    (Connectors::make('gandi')->section('records')->toolbarAction('create')->run)($api, [], ['type' => 'TXT', 'name' => '_dmarc', 'values' => 'v=DMARC1; p=none; rua=mailto:a@example.com,mailto:b@example.com', 'ttl' => 300], ['domain' => 'example.com']);
    $check(($api->sent('POST', '/livedns/domains/example.com/records')['json']['rrset_values'] ?? null) === ['v=DMARC1; p=none; rua=mailto:a@example.com,mailto:b@example.com'], 'Gandi: a TXT with commas stays one value');
    final class FakeNamecheap extends CipiGui\Infrastructure\NamecheapClient
    {
        public array $queries = [];
        protected function raw(string $query): string
        {
            parse_str($query, $q);
            $this->queries[] = $q;
            return match ($q['Command']) {
                'namecheap.domains.dns.getHosts' => '<ApiResponse Status="OK"><CommandResponse><DomainDNSGetHostsResult Domain="example.co.uk" EmailType="FWD"><host HostId="1" Name="@" Type="A" Address="198.51.100.4" MXPref="10" TTL="1800"/><host HostId="2" Name="www" Type="CNAME" Address="example.co.uk." MXPref="10" TTL="1800"/></DomainDNSGetHostsResult></CommandResponse></ApiResponse>',
                'namecheap.domains.dns.setHosts' => '<ApiResponse Status="OK"><CommandResponse/></ApiResponse>',
                default => '<ApiResponse Status="ERROR"><Errors><Error Number="1011150">Parameter RequestIP is invalid</Error></Errors></ApiResponse>',
            };
        }
    }
    $nc = new FakeNamecheap('https://api.namecheap.com', [], ['user' => 'andrea', 'key' => 'k', 'username' => 'andrea', 'ip' => '203.0.113.10']);
    $hosts = Connectors::make('namecheap')->section('hosts');
    ($hosts->action('edit')->run)($nc, ['HostId' => '1'], ['Type' => 'A', 'Name' => '@', 'Address' => 'mozart', 'TTL' => 300, 'MXPref' => 10], ['domain' => 'example.co.uk']);
    $set = end($nc->queries);
    $check($set['SLD'] === 'example' && $set['TLD'] === 'co.uk' && $set['Address1'] === '203.0.113.10' && $set['HostName2'] === 'www' && $set['Address2'] === 'example.co.uk.' && $set['EmailType'] === 'FWD' && $set['ClientIp'] === '203.0.113.10', 'Namecheap: every record written back, the one changed to mozart, mail forwarding kept');
    $check(str_contains((string) $threw(fn () => $nc->command('namecheap.users.getBalances')), '1011150: Parameter RequestIP is invalid'), 'Namecheap: its error number and words');
    $api = new FakeApi();
    (Connectors::make('contabo')->section('instances')->action('shutdown')->run)($api, ['instanceId' => 123, 'status' => 'running'], [], []);
    $check($api->sent('POST', '/compute/instances/123/actions/shutdown') !== null, 'Contabo: shut down an instance');
    $api = new FakeApi();
    (Connectors::make('upcloud')->section('firewall')->toolbarAction('create')->run)($api, [], ['ports' => '22, 53/udp', 'from' => '198.51.100.7'], ['server' => 'u1']);
    $rules = array_values(array_filter($api->calls, fn ($c) => $c[1] === '/server/u1/firewall_rule'));
    $check(count($rules) === 2 && ($rules[0][2]['json']['firewall_rule']['destination_port_start'] ?? null) === '22' && ($rules[1][2]['json']['firewall_rule']['protocol'] ?? null) === 'udp' && ($rules[0][2]['json']['firewall_rule']['source_address_start'] ?? null) === '198.51.100.7', 'UpCloud: "22, 53/udp" from one address, a rule each');

    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail === 0 ? 0 : 1);
}
