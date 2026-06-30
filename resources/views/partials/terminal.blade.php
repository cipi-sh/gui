<div class="terminal">
    <div class="terminal-header">
        <div class="terminal-dot" style="background:#ff5f57;"></div>
        <div class="terminal-dot" style="background:#febc2e;"></div>
        <div class="terminal-dot" style="background:#28c840;"></div>
        <span class="text-xs text-surface-400 ml-2">{{ $title ?? 'logs' }}</span>
        @if(isset($subtitle))
            <span class="text-xs text-surface-500 ml-auto">{{ $subtitle }}</span>
        @endif
    </div>
    <div class="terminal-body" @if($autoScroll ?? true) x-data x-init="$el.scrollTop = $el.scrollHeight" @endif>
        @forelse($lines as $line)
            @php
                $class = 'terminal-line';
                if (str_contains(strtolower($line), 'error') || str_contains(strtolower($line), 'fatal')) {
                    $class .= ' error';
                } elseif (str_contains(strtolower($line), 'warn')) {
                    $class .= ' warn';
                } elseif (str_starts_with(trim($line), '#') || str_starts_with(trim($line), '--')) {
                    $class .= ' dim';
                }
            @endphp
            <div class="{{ $class }}">{{ $line }}</div>
        @empty
            <div class="terminal-line dim">No output.</div>
        @endforelse
    </div>
</div>
