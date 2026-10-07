<div @if($autoRefresh) wire:poll.5s="polledRefresh" @endif>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div class="segmented" role="tablist" aria-label="Log type">
            @foreach($this->logTypeOptions() as $type)
                <button type="button" role="tab" aria-selected="{{ $logType === $type ? 'true' : 'false' }}" wire:click="$set('logType', '{{ $type }}')" class="segmented-item {{ $logType === $type ? 'active' : '' }}">
                    {{ ['all' => 'All', 'php' => 'PHP-FPM', 'nginx' => 'Nginx'][$type] ?? ucfirst($type) }}
                </button>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <label class="check text-sm">
                <input type="checkbox" wire:model.live="autoRefresh">
                Live (5s)
                @if($autoRefresh)<span class="dot dot-accent dot-pulse"></span>@endif
            </label>
            <div class="btn-group">
                <button type="button" wire:click="nextPage" class="btn btn-secondary btn-sm" title="Older lines">← Older</button>
                <span class="text-xs text-muted self-center tabular-nums">Page {{ $page }}</span>
                <button type="button" wire:click="prevPage" @disabled($page <= 1) class="btn btn-secondary btn-sm" title="Newer lines">Newer →</button>
            </div>
            <button type="button" wire:click="refresh" class="btn btn-ghost btn-icon btn-sm" aria-label="Reload logs"><x-cipi::icon name="refresh" wire:loading.class="animate-spin" wire:target="refresh,loadLogs" /></button>
        </div>
    </div>

    @if($error)
        <x-cipi::alert type="danger" class="mb-4">{{ $error }}</x-cipi::alert>
    @endif

    @if($hint)
        <x-cipi::alert type="info" class="mb-4">{{ $hint }}</x-cipi::alert>
    @endif

    @if($loading && empty($files))
        <div class="terminal"><div class="terminal-body"><div class="terminal-line dim">Loading logs…</div></div></div>
    @elseif(empty($files))
        @include('cipi-gui::partials.terminal', ['lines' => [], 'title' => $appName.' — '.($logType === 'all' ? 'all logs' : $logType)])
    @else
        <div class="space-y-4">
            @foreach($files as $file)
                @include('cipi-gui::partials.terminal', [
                    'lines' => $file['lines'] ?? [],
                    'title' => $file['path'] ?? 'log',
                    'subtitle' => number_format((int) ($file['total_lines'] ?? 0)).' lines · page '.($file['page'] ?? 1).'/'.($file['total_pages'] ?? 1),
                    'tall' => count($files) === 1,
                ])
            @endforeach
        </div>
    @endif

    @if(!empty($warnings))
        <x-cipi::alert type="warn" title="Warnings" class="mt-4">
            @foreach($warnings as $warning)<p>{{ $warning }}</p>@endforeach
        </x-cipi::alert>
    @endif
</div>
