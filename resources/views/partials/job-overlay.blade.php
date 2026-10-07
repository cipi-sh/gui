@if($showJobOverlay ?? false)
@php
    $status = $activeJobStatus ?? 'pending';
    $secrets = $status === 'completed' ? $this->jobSecrets() : [];
@endphp
<div class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="job-overlay-title"
     @if($jobRunning ?? false) wire:poll.{{ config('cipi-gui.job_poll_interval_ms', 1500) }}ms="pollJob" @endif>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <div class="flex items-center gap-3 min-w-0">
                @if($status === 'completed')
                    <div class="job-status-icon job-status-icon-success" aria-hidden="true"><x-cipi::icon name="check" stroke="2.5" /></div>
                @elseif($status === 'failed')
                    <div class="job-status-icon job-status-icon-error" aria-hidden="true"><x-cipi::icon name="x" stroke="2.5" /></div>
                @else
                    <div class="spinner spinner-lg" aria-hidden="true"></div>
                @endif
                <div class="min-w-0">
                    <h2 id="job-overlay-title" class="modal-title truncate">{{ $jobLabel ?: 'Processing…' }}</h2>
                    <p class="text-xs text-muted mt-0.5 flex flex-wrap items-center gap-2">
                        <span class="badge {{ $status === 'completed' ? 'badge-green' : ($status === 'failed' ? 'badge-red' : 'badge-accent') }}">{{ ucfirst($status) }}</span>
                        @if($activeJobStartedAt)
                            <span class="tabular-nums"
                                  x-data="{ start: {{ (int) $activeJobStartedAt }}, end: {{ $activeJobFinishedAt ? (int) $activeJobFinishedAt : 'null' }}, now: Date.now() }"
                                  x-init="if (!end) { const t = setInterval(() => { now = Date.now(); if (end) clearInterval(t) }, 1000) }"
                                  x-text="Math.max(0, Math.round(((end ?? now) - start) / 1000)) + 's'"></span>
                        @endif
                        @if($activeJobType)
                            <code class="text-2xs">{{ $activeJobType }}</code>
                        @endif
                    </p>
                </div>
            </div>
            @if(!($jobRunning ?? false))
                <button type="button" class="btn btn-ghost btn-icon btn-sm" wire:click="dismissJob" aria-label="Close"><x-cipi::icon name="x" /></button>
            @endif
        </div>

        <div class="modal-body space-y-4">
            @if($activeJobError)
                <x-cipi::alert type="danger" title="The job failed">{{ $activeJobError }}</x-cipi::alert>
            @endif

            @if($showDeployHints && !empty($deployHints))
                <x-cipi::alert type="warn" title="Deploy troubleshooting">
                    <ul class="space-y-1 mt-1" style="list-style:disc;padding-left:1.1rem;">
                        @foreach($deployHints as $hint)
                            <li>{{ $hint }}</li>
                        @endforeach
                    </ul>
                </x-cipi::alert>
            @endif

            @if($secrets !== [])
                <div class="card p-4" style="border-color: var(--accent-line);">
                    <div class="flex items-center gap-2 mb-2">
                        <x-cipi::icon name="key" class="h-4 w-4 text-accent" />
                        <p class="font-semibold text-strong">Save these credentials now</p>
                    </div>
                    <p class="text-xs text-muted mb-2">They are shown once and are not stored by the panel.</p>
                    @foreach($secrets as $secret)
                        <x-cipi::secret :label="$secret['label']" :value="$secret['value']" :masked="$secret['masked']" />
                    @endforeach
                </div>
            @endif

            @if($activeJobOutput)
                @include('cipi-gui::partials.terminal', ['lines' => explode("\n", $activeJobOutput), 'title' => 'cipi · '.($activeJobType ?? 'output')])
            @elseif($activeJobResult && $secrets === [])
                @include('cipi-gui::partials.terminal', [
                    'lines' => explode("\n", json_encode($activeJobResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
                    'title' => 'result.json',
                ])
            @elseif($jobRunning ?? false)
                <div class="terminal">
                    <div class="terminal-header">
                        <span class="terminal-dot" style="background:#ff5f57;"></span>
                        <span class="terminal-dot" style="background:#febc2e;"></span>
                        <span class="terminal-dot" style="background:#28c840;"></span>
                        <span class="terminal-title">waiting for the server…</span>
                    </div>
                    <div class="terminal-body">
                        <div class="terminal-line dim">$ queued on the Cipi API — output appears when the job finishes</div>
                        <div class="terminal-line"><span class="spinner" style="width:.75rem;height:.75rem;vertical-align:middle;"></span></div>
                    </div>
                </div>
            @endif

            @if($activeJobId)
                <p class="text-2xs text-subtle font-mono">job {{ $activeJobId }}</p>
            @endif
        </div>

        <div class="modal-footer">
            @if($jobRunning ?? false)
                <span class="text-xs text-muted mr-auto self-center">The job keeps running on the server if you close this.</span>
                <button type="button" wire:click="dismissJob" class="btn btn-secondary">Run in background</button>
            @else
                <button type="button" wire:click="dismissJob" class="btn btn-primary">Done</button>
            @endif
        </div>
    </div>
</div>
@endif
