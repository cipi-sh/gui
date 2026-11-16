{{-- The Backups tab of a server: `cipi backup` as a matrix — one column per profile, one row
     per app and per database, a mark where the profile takes it. Data: $backup (cipi backup
     status --json through the API), $backupRuns (asked on demand), $canManage. --}}
@php
    $profiles = $backup['profiles'] ?? [];
    $destinations = $backup['destinations'] ?? [];
    $uncoveredApps = $backup['uncovered']['apps'] ?? [];
    $uncoveredDbs = array_map(fn ($d) => ($d['engine'] ?? '').'/'.($d['name'] ?? ''), $backup['uncovered']['databases'] ?? []);
    $covers = [];
    foreach ($profiles as $p) {
        $covers[$p['name']] = [
            'apps' => array_flip((array) ($p['covers']['apps'] ?? [])),
            'dbs' => array_flip(array_map(fn ($d) => ($d['engine'] ?? '').'/'.($d['name'] ?? ''), (array) ($p['covers']['databases'] ?? []))),
        ];
    }
    $stateBadge = ['running' => 'badge-blue', 'disabled' => 'badge-gray', 'overdue' => 'badge-amber', 'failed' => 'badge-red', 'ok' => 'badge-green', 'never' => 'badge-neutral'];
    $stateLabel = ['running' => 'Running', 'disabled' => 'Disabled', 'overdue' => 'Overdue', 'failed' => 'Failed', 'ok' => 'OK', 'never' => 'No run yet'];
@endphp

@if(! ($backup['configured'] ?? false))
    {{-- Only drawn when the server itself answered "configured: false" (ManagesBackups::loadBackups):
         a failed or shapeless answer is an error in the tab, never this card. --}}
    @php
        $bkHost = $backup['host'] ?? null;
        $bkFile = $backup['config_file'] ?? null;
    @endphp
    <div class="card" data-backup-unconfigured>
        <x-cipi::empty icon="cloud" title="Backups are not configured on this server">
            Run <code>cipi backup configure</code> on the server once: it asks where the archives go — a directory on the server, an S3 bucket, or both. Profiles are then managed here.
            <x-slot:actions>
                <button type="button" wire:click="refresh" class="btn btn-secondary btn-sm" wire:loading.attr="disabled" wire:target="refresh"><x-cipi::icon name="refresh" /> Check again</button>
            </x-slot:actions>
        </x-cipi::empty>
        <div class="field-hint mt-4 text-center">
            <p>
                This is the answer of <code>cipi backup status --json</code>@if($bkHost) on <code>{{ $bkHost }}</code>@endif: it found no <code>{{ $bkFile ?: '/etc/cipi/backup.json' }}</code>, the file <code>cipi backup configure</code> writes.
            </p>
            <p class="mt-1">
                Already configured? Check that this is the server you configured, then compare there:
                <code>ls -l {{ $bkFile ?: '/etc/cipi/backup.json' }}</code> and <code>sudo -u www-data sudo -n /usr/local/bin/cipi backup status --json</code> — the second is exactly what the panel runs.
            </p>
        </div>
    </div>
@else
    <div @if($this->backupRunning()) wire:poll.10s="refreshBackups" @endif>
        @if($uncoveredApps || $uncoveredDbs)
            <x-cipi::alert type="warn" class="mb-4" title="Not in any enabled profile">
                @if($uncoveredApps){{ count($uncoveredApps) }} {{ count($uncoveredApps) === 1 ? 'app' : 'apps' }} ({{ implode(', ', array_slice($uncoveredApps, 0, 6)) }}{{ count($uncoveredApps) > 6 ? '…' : '' }})@endif
                @if($uncoveredApps && $uncoveredDbs) and @endif
                @if($uncoveredDbs){{ count($uncoveredDbs) }} {{ count($uncoveredDbs) === 1 ? 'database' : 'databases' }} ({{ implode(', ', array_map(fn ($d) => explode('/', $d, 2)[1] ?? $d, array_slice($uncoveredDbs, 0, 6))) }}{{ count($uncoveredDbs) > 6 ? '…' : '' }})@endif
                {{ (count($uncoveredApps) + count($uncoveredDbs)) === 1 ? 'is' : 'are' }} not backed up. The rows are marked below.
            </x-cipi::alert>
        @endif

        {{-- Destinations --}}
        <div class="card mb-4">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Destinations</h2>
                    <p class="card-subtitle">Where archives go. A bucket is added on the server (<code>cipi backup destination add</code>): it takes a secret key, which the panel never handles.</p>
                </div>
                @if($canManage && count($destinations) > 1)
                    <button type="button" wire:click="testBackupDestinations" class="btn btn-secondary btn-sm"><x-cipi::icon name="signal" /> Test buckets</button>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach($destinations as $dest)
                    <div class="backup-dest" wire:key="backup-dest-{{ $dest['name'] }}">
                        <p class="font-semibold text-strong flex items-center gap-1.5"><x-cipi::icon :name="($dest['type'] ?? '') === 'local' ? 'server' : 'cloud'" class="h-3.5 w-3.5 text-muted" /> {{ $dest['name'] }}@if(!empty($dest['default'])) <span class="badge badge-mono">default bucket</span>@endif</p>
                        <p class="text-xs text-muted font-mono truncate" title="{{ ($dest['type'] ?? '') === 'local' ? ($dest['path'] ?? '') : 's3://'.($dest['bucket'] ?? '').(!empty($dest['endpoint']) ? ' · '.$dest['endpoint'] : '') }}">{{ ($dest['type'] ?? '') === 'local' ? ($dest['path'] ?? '') : 's3://'.($dest['bucket'] ?? '') }}</p>
                        <p class="text-2xs text-subtle mt-1">{{ ($n = count((array) ($dest['profiles'] ?? []))) === 0 ? 'not used by a profile' : 'used by '.$n.' '.($n === 1 ? 'profile' : 'profiles') }}</p>
                    </div>
                @endforeach
            </div>
            @if($backupTest)
                <div class="mt-3">@include('cipi-gui::partials.terminal', ['lines' => explode("\n", $backupTest['output'] !== '' ? $backupTest['output'] : ($backupTest['ok'] ? 'reachable' : 'no output')), 'title' => 'cipi backup destination test', 'autoScroll' => false])</div>
            @endif
        </div>

        {{-- The matrix --}}
        <div class="card card-flush">
            <div class="card-header p-5 mb-0">
                <div>
                    <h2 class="card-title">What is backed up, by which profile</h2>
                    <p class="card-subtitle">A column per profile: what it takes, how often, where it goes and how its last run went. A mark where a profile takes that app's files or that database.</p>
                </div>
                @if($canManage)
                    <div class="btn-actions">
                        @if(count($profiles) > 1)
                            <button type="button" wire:click="runBackup('')" data-confirm="Run every enabled profile now? Each starts on the server and carries on by itself." data-confirm-label="Run all" class="btn btn-secondary btn-sm" @disabled($this->backupRunning())><x-cipi::icon name="play" /> Run all</button>
                        @endif
                        <button type="button" wire:click="openBackupForm('')" class="btn btn-primary btn-sm"><x-cipi::icon name="plus" /> New profile</button>
                    </div>
                @endif
            </div>

            @if(count($profiles) === 0)
                <div class="p-5 pt-0">
                    <x-cipi::empty icon="cloud" title="No backup profile yet">
                        A profile says what is taken, how often, where it goes and how long it is kept.
                        {{-- The closing slot tag and the endif stay on separate lines: Blade turns that tag into its
                             end-of-slot directive, and an endif glued to it is no longer read as a directive — its
                             if was then never closed, and the view did not compile. --}}
                        @if($canManage)
                            <x-slot:actions><button type="button" wire:click="openBackupForm('')" class="btn btn-primary"><x-cipi::icon name="plus" /> Create the first profile</button></x-slot:actions>
                        @endif
                    </x-cipi::empty>
                </div>
            @else
                <div class="table-scroll">
                    <table class="backup-matrix">
                        <thead>
                            <tr>
                                <th class="backup-matrix-corner">&nbsp;</th>
                                @foreach($profiles as $p)
                                    @php $state = $this->backupProfileState($p); @endphp
                                    <th class="backup-matrix-profile" wire:key="backup-col-{{ $p['name'] }}" data-backup-profile="{{ $p['name'] }}">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-semibold text-strong normal-case">{{ $p['name'] }}</span>
                                            <span class="badge {{ $stateBadge[$state] }}" data-backup-state="{{ $state }}">@if($state === 'running')<span class="spinner" style="width:.6rem;height:.6rem;border-width:1.5px"></span> @endif{{ $stateLabel[$state] }}</span>
                                        </div>
                                        <dl class="backup-matrix-facts">
                                            <dt>Takes</dt><dd>{{ ['all' => 'files + databases', 'files' => 'files', 'db' => 'databases'][$p['scope'] ?? 'all'] ?? $p['scope'] }}</dd>
                                            <dt>When</dt><dd title="{{ $p['cron'] ?? '' }}">{{ $this->backupScheduleLabel($p) }}</dd>
                                            <dt>Where</dt><dd>{{ implode(', ', (array) ($p['destinations'] ?? [])) ?: '—' }}@if(!empty($p['encrypt'])) <x-cipi::icon name="lock" class="h-3 w-3 inline" /><span class="sr-only">encrypted</span>@endif</dd>
                                            <dt>Keeps</dt><dd>{{ $this->backupRetentionLabel($p) }}</dd>
                                            <dt>Last run</dt>
                                            <dd title="{{ $p['last']['detail'] ?? '' }}">
                                                @if(!empty($p['last']['epoch']))
                                                    {{ \CipiGui\Support\Clock::local((int) $p['last']['epoch'])?->diffForHumans(['short' => true, 'parts' => 1]) }}@if(($p['last']['status'] ?? '') === 'error') — <span class="text-danger">{{ $p['last']['detail'] ?? 'failed' }}</span>@endif
                                                @else
                                                    never
                                                @endif
                                            </dd>
                                        </dl>
                                        @if($canManage)
                                            <div class="flex flex-wrap gap-1 mt-2">
                                                <button type="button" wire:click="runBackup(@js($p['name']))" class="btn btn-secondary btn-xs" @disabled(! empty($p['running'])) title="Start a run of this profile now"><x-cipi::icon name="play" /> Run</button>
                                                <button type="button" wire:click="openBackupForm(@js($p['name']))" class="btn btn-ghost btn-xs"><x-cipi::icon name="pencil" /> Edit</button>
                                                <div class="dropdown" x-data="{ open: false }" x-on:click.outside="open = false">
                                                    <button type="button" class="btn btn-ghost btn-icon btn-xs" x-on:click="open = !open" aria-label="More actions for profile {{ $p['name'] }}"><x-cipi::icon name="more" /></button>
                                                    <div class="dropdown-menu" x-show="open" x-cloak x-transition.opacity.duration.150ms>
                                                        <button type="button" class="dropdown-item" wire:click="verifyBackup(@js($p['name']))" x-on:click="open = false"><x-cipi::icon name="shield" /> Verify the newest run</button>
                                                        @if(!empty($p['enabled']))
                                                            <button type="button" class="dropdown-item" wire:click="toggleBackupProfile(@js($p['name']), false)" data-confirm="Disable profile {{ $p['name'] }}? It leaves the schedule; its archives stay." data-confirm-label="Disable" x-on:click="open = false"><x-cipi::icon name="pause" /> Disable</button>
                                                        @else
                                                            <button type="button" class="dropdown-item" wire:click="toggleBackupProfile(@js($p['name']), true)" x-on:click="open = false"><x-cipi::icon name="play" /> Enable</button>
                                                        @endif
                                                        <div class="dropdown-divider"></div>
                                                        <button type="button" class="dropdown-item danger" wire:click="deleteBackupProfile(@js($p['name']))" data-confirm="Remove profile {{ $p['name'] }}? Its archives stay on every destination; what it covered is no longer backed up by it." data-confirm-label="Remove profile" data-confirm-danger x-on:click="open = false"><x-cipi::icon name="trash" /> Remove profile</button>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="backup-matrix-section"><th colspan="{{ count($profiles) + 1 }}">Apps — files ({{ count($backup['apps'] ?? []) }})</th></tr>
                            @forelse($backup['apps'] ?? [] as $appName)
                                @php $bare = in_array($appName, $uncoveredApps, true); @endphp
                                <tr wire:key="backup-app-{{ $appName }}" @class(['backup-matrix-bare' => $bare])>
                                    <th scope="row"><a href="{{ route('cipi-gui.apps.show', ['name' => $appName, 'server' => $server->id]) }}" class="row-link">{{ $appName }}</a>@if($bare) <span class="badge badge-amber">not backed up</span>@endif</th>
                                    @foreach($profiles as $p)
                                        <td class="backup-matrix-cell">@if(isset($covers[$p['name']]['apps'][$appName]))<span class="backup-mark {{ empty($p['enabled']) ? 'is-off' : '' }}" title="{{ $p['name'] }} takes the files of {{ $appName }}{{ empty($p['enabled']) ? ' (profile disabled)' : '' }}"><x-cipi::icon name="check" /></span><span class="sr-only">yes</span>@else<span class="text-subtle" aria-hidden="true">·</span><span class="sr-only">no</span>@endif</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($profiles) + 1 }}" class="text-muted">No apps on this server.</td></tr>
                            @endforelse
                            <tr class="backup-matrix-section"><th colspan="{{ count($profiles) + 1 }}">Databases ({{ count($backup['databases'] ?? []) }})</th></tr>
                            @forelse($backup['databases'] ?? [] as $db)
                                @php $key = ($db['engine'] ?? '').'/'.($db['name'] ?? ''); $bare = in_array($key, $uncoveredDbs, true); @endphp
                                <tr wire:key="backup-db-{{ $key }}" @class(['backup-matrix-bare' => $bare])>
                                    <th scope="row"><span class="font-mono">{{ $db['name'] ?? '' }}</span> <span class="badge {{ ($db['engine'] ?? '') === 'pgsql' ? 'badge-blue' : 'badge-neutral' }}">{{ $this->engineLabel($db['engine'] ?? null) }}</span>@if($bare) <span class="badge badge-amber">not backed up</span>@endif</th>
                                    @foreach($profiles as $p)
                                        <td class="backup-matrix-cell">@if(isset($covers[$p['name']]['dbs'][$key]))<span class="backup-mark {{ empty($p['enabled']) ? 'is-off' : '' }}" title="{{ $p['name'] }} dumps {{ $db['name'] ?? '' }}{{ empty($p['enabled']) ? ' (profile disabled)' : '' }}"><x-cipi::icon name="check" /></span><span class="sr-only">yes</span>@else<span class="text-subtle" aria-hidden="true">·</span><span class="sr-only">no</span>@endif</td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($profiles) + 1 }}" class="text-muted">No databases on this server.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Stored runs: asked on demand (it lists every bucket) --}}
        @if(count($profiles) > 0)
            <div class="card mt-4">
                <div class="card-header">
                    <div>
                        <h2 class="card-title">Stored runs</h2>
                        <p class="card-subtitle">What each destination holds right now. Downloading or restoring one is done on the server: <code>cipi backup fetch &lt;profile&gt; &lt;run&gt;</code>.</p>
                    </div>
                    <button type="button" wire:click="loadBackupRuns" class="btn btn-secondary btn-sm"><x-cipi::icon name="refresh" /> {{ $backupRuns === null ? 'List stored runs' : 'List again' }}</button>
                </div>
                @if($backupRuns === null)
                    <p class="text-sm text-muted">Not listed yet: it asks every bucket, which takes a few seconds.</p>
                @else
                    @foreach($backupRunErrors as $runError)
                        <x-cipi::alert type="warn" class="mb-3">{{ $runError }}</x-cipi::alert>
                    @endforeach
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @foreach($profiles as $p)
                            @php $runs = (array) ($backupRuns[$p['name']] ?? []); @endphp
                            <div wire:key="backup-runs-{{ $p['name'] }}">
                                <p class="font-medium text-strong mb-1">{{ $p['name'] }} <span class="text-subtle font-normal">· {{ count($runs) }} {{ count($runs) === 1 ? 'run' : 'runs' }}</span></p>
                                @if($runs)
                                    <ul class="backup-runs">
                                        @foreach(array_slice($runs, 0, 12) as $run)
                                            <li><span class="font-mono text-xs">{{ str_replace('_', ' ', (string) ($run['run'] ?? '')) }}</span><span class="text-xs text-muted">{{ implode(', ', (array) ($run['destinations'] ?? [])) }}</span></li>
                                        @endforeach
                                    </ul>
                                    @if(count($runs) > 12)<p class="text-2xs text-subtle mt-1">and {{ count($runs) - 12 }} older</p>@endif
                                @else
                                    <p class="text-xs text-muted">No run stored yet.</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
@endif

@if($showBackupForm)
    <x-cipi::modal :title="$backupEditing ? 'Edit profile '.$backupEditing : 'New backup profile'" close="closeBackupForm" persistent size="lg">
        <form wire:submit="saveBackupProfile">
            <div class="modal-body space-y-4">
                @unless($backupEditing)
                    <div>
                        <label for="bp-name">Name</label>
                        <input id="bp-name" type="text" wire:model="bpName" placeholder="nightly" autocomplete="off" autofocus>
                    </div>
                @endunless
                @error('bpName') <p class="field-error">{{ $message }}</p> @enderror
                <div>
                    <span class="label">What it takes</span>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                        @foreach(['all' => ['Files and databases', 'Everything a restore needs'], 'files' => ['Files only', 'shared/ of Laravel apps, htdocs/ of custom ones'], 'db' => ['Databases only', 'Cheap enough to run often']] as $value => [$title, $text])
                            <label class="check-card"><input type="radio" wire:model.live="bpScope" value="{{ $value }}"><span><span class="check-card-title">{{ $title }}</span><span class="check-card-text">{{ $text }}</span></span></label>
                        @endforeach
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @if($bpScope !== 'db')
                        <div>
                            <label for="bp-apps">Apps</label>
                            <input id="bp-apps" type="text" wire:model="bpApps" class="font-mono" placeholder="*">
                            <p class="field-hint">Names or globs, comma-separated. <code>*</code> is every app, also the ones created later.</p>
                            @error('bpApps') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    @if($bpScope !== 'files')
                        <div>
                            <label for="bp-dbs">Databases</label>
                            <input id="bp-dbs" type="text" wire:model="bpDatabases" class="font-mono" placeholder="*">
                            <p class="field-hint">Names or globs: <code>shop, tenant_*</code>. <code>*</code> is every database.</p>
                            @error('bpDatabases') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="bp-exdbs">Databases to leave out <span class="text-subtle font-normal">— optional</span></label>
                            <input id="bp-exdbs" type="text" wire:model="bpExcludeDatabases" class="font-mono" placeholder="tmp_*">
                            @error('bpExcludeDatabases') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="bp-extbl">Tables to leave out <span class="text-subtle font-normal">— optional</span></label>
                            <input id="bp-extbl" type="text" wire:model="bpExcludeTables" class="font-mono" placeholder="*.jobs, *.telescope_*">
                            @error('bpExcludeTables') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="bp-schedule">When</label>
                        <select id="bp-schedule" wire:model.live="bpSchedule">
                            <option value="every">At a regular interval</option>
                            <option value="cron">A cron expression</option>
                        </select>
                    </div>
                    <div>
                        @if($bpSchedule === 'cron')
                            <label for="bp-cron">Cron expression</label>
                            <input id="bp-cron" type="text" wire:model="bpCron" class="font-mono" placeholder="0 2 * * *">
                            <p class="field-hint">Five fields, the server's time: minute hour day month weekday.</p>
                            @error('bpCron') <p class="field-error">{{ $message }}</p> @enderror
                        @else
                            <label for="bp-every">Interval</label>
                            <select id="bp-every" wire:model="bpEvery">
                                @foreach(\CipiGui\Livewire\ServerManage::BACKUP_EVERY as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('bpEvery') <p class="field-error">{{ $message }}</p> @enderror
                        @endif
                    </div>
                </div>
                <div>
                    <span class="label">Where it goes</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach($backup['destinations'] ?? [] as $dest)
                            <label class="check-card" wire:key="bp-dest-{{ $dest['name'] }}"><input type="checkbox" wire:model="bpDestinations" value="{{ $dest['name'] }}"><span><span class="check-card-title">{{ $dest['name'] }}</span><span class="check-card-text">{{ ($dest['type'] ?? '') === 'local' ? 'On this server' : 's3://'.($dest['bucket'] ?? '') }}</span></span></label>
                        @endforeach
                    </div>
                    <p class="field-hint">A copy on the server alone is lost with the server: add a bucket for anything you cannot afford to lose.</p>
                    @error('bpDestinations') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <span class="label">How long it is kept</span>
                    <div class="grid grid-cols-3 gap-3">
                        <div><label for="bp-keep" class="text-xs text-muted">The last N runs</label><input id="bp-keep" type="number" min="0" wire:model="bpKeep"></div>
                        <div><label for="bp-days" class="text-xs text-muted">Days</label><input id="bp-days" type="number" min="0" wire:model="bpKeepDays"></div>
                        <div><label for="bp-weeks" class="text-xs text-muted">Weeks</label><input id="bp-weeks" type="number" min="0" wire:model="bpKeepWeeks"></div>
                    </div>
                    <p class="field-hint">0 turns a rule off; at least one must be set. A run is kept while any rule still wants it.</p>
                    @error('bpKeep') <p class="field-error">{{ $message }}</p> @enderror
                    @error('bpKeepDays') <p class="field-error">{{ $message }}</p> @enderror
                    @error('bpKeepWeeks') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-start gap-2">
                    <input type="checkbox" wire:model="bpEncrypt" class="mt-1">
                    <span><span class="font-medium text-strong">Encrypt the archives</span><span class="block text-xs text-muted">AES-256 before they leave the server. The key is created on the server on first use: save it elsewhere (<code>cipi backup key show</code>) — without it the archives cannot be read.</span></span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" wire:click="closeBackupForm" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">{{ $backupEditing ? 'Save profile' : 'Create profile' }}</button>
            </div>
        </form>
    </x-cipi::modal>
@endif
