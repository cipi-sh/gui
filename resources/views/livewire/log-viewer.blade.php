<div wire:poll.5s="polledRefresh">
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
            <select wire:model.live="logType" class="text-sm" style="width:auto;">
                <option value="all">All logs</option>
                @foreach($availableTypes as $type)
                    <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                @endforeach
            </select>
            <label class="flex items-center gap-2 text-sm text-surface-400" style="margin:0;">
                <input type="checkbox" wire:model.live="autoRefresh">
                Auto-refresh (5s)
            </label>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="prevPage" @if($page <= 1) disabled @endif class="btn btn-ghost btn-sm">Older</button>
            <span class="text-sm text-surface-400">Page {{ $page }}</span>
            <button wire:click="nextPage" class="btn btn-ghost btn-sm">Newer</button>
            <button wire:click="refresh" class="btn btn-secondary btn-sm">Refresh</button>
        </div>
    </div>

    @if($error)
        <div class="card border-red-800 bg-red-900/20 mb-4 text-sm text-red-400">{{ $error }}</div>
    @endif

    @if($loading)
        <div class="flex items-center gap-3 py-8 justify-center">
            <div class="spinner"></div>
            <span class="text-surface-400 text-sm">Loading logs...</span>
        </div>
    @elseif(empty($files))
        <div class="terminal">
            <div class="terminal-header">
                <div class="terminal-dot" style="background:#ff5f57;"></div>
                <div class="terminal-dot" style="background:#febc2e;"></div>
                <div class="terminal-dot" style="background:#28c840;"></div>
                <span class="text-xs text-surface-400 ml-2">{{ $appName }} — logs</span>
            </div>
            <div class="terminal-body">
                <div class="terminal-line dim">No log files found for this app.</div>
            </div>
        </div>
    @else
        @foreach($files as $file)
            @include('cipi-gui::partials.terminal', [
                'lines' => $file['lines'] ?? [],
                'title' => basename($file['path'] ?? 'log'),
                'subtitle' => ($file['total_lines'] ?? 0).' lines · page '.$file['page'].'/'.$file['total_pages'],
            ])
            @if(!$loop->last)<div class="mb-4"></div>@endif
        @endforeach
    @endif

    @if(!empty($warnings))
        <div class="mt-4 card border-amber-600/30 bg-amber-600/10">
            <p class="text-sm text-amber-400 font-medium mb-1">Warnings</p>
            @foreach($warnings as $warning)
                <p class="text-xs text-amber-400/80">{{ $warning }}</p>
            @endforeach
        </div>
    @endif
</div>
