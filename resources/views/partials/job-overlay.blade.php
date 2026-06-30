@if($jobRunning ?? false)
<div class="modal-overlay" wire:poll.{{ config('cipi-gui.job_poll_interval_ms', 1500) }}ms="pollJob">
    <div class="modal-content" style="max-width:36rem;">
        <div class="p-6">
            <div class="flex items-center gap-4 mb-4">
                <div class="relative">
                    <div class="spinner spinner-lg"></div>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-white">{{ $jobLabel ?? 'Processing...' }}</h3>
                    <p class="text-sm text-surface-400 mt-1">
                        Status: <span class="badge badge-blue">{{ ucfirst($activeJobStatus ?? 'pending') }}</span>
                    </p>
                </div>
            </div>

            @if($activeJobId)
                <p class="text-xs text-surface-500 font-mono mb-4">Job ID: {{ $activeJobId }}</p>
            @endif

            @if($activeJobOutput)
                @include('cipi-gui::partials.terminal', ['lines' => explode("\n", $activeJobOutput), 'title' => 'CLI Output'])
            @elseif($activeJobResult)
                <div class="terminal mt-4">
                    <div class="terminal-header">
                        <div class="terminal-dot" style="background:#ff5f57;"></div>
                        <div class="terminal-dot" style="background:#febc2e;"></div>
                        <div class="terminal-dot" style="background:#28c840;"></div>
                        <span class="text-xs text-surface-400 ml-2">result.json</span>
                    </div>
                    <div class="terminal-body">
                        <pre class="text-terminal-green" style="margin:0;white-space:pre-wrap;">{{ json_encode($activeJobResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                </div>
            @else
                <div class="card mt-2">
                    <div class="flex items-center gap-3">
                        <div class="spinner"></div>
                        <span class="text-sm text-surface-400">Waiting for server response...</span>
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-2 mt-6">
                @if(!($jobRunning ?? false))
                    <button wire:click="dismissJob" class="btn btn-primary">Done</button>
                @else
                    <button wire:click="dismissJob" class="btn btn-secondary">Run in background</button>
                @endif
            </div>
        </div>
    </div>
</div>
@endif
