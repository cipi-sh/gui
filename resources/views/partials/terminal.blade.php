@php
    $terminalLines = $lines ?? [];
    $terminalText = implode("\n", $terminalLines);
    $terminalMd = "```text\n".$terminalText.($terminalText !== '' ? "\n" : '')."```";
@endphp
<div class="terminal" x-data="{ copied: false, md: {{ \Illuminate\Support\Js::from($terminalMd) }}, raw: {{ \Illuminate\Support\Js::from($terminalText) }} }">
    <div class="terminal-header">
        <span class="terminal-dot" style="background:#ff5f57;"></span>
        <span class="terminal-dot" style="background:#febc2e;"></span>
        <span class="terminal-dot" style="background:#28c840;"></span>
        <span class="terminal-title" title="{{ $title ?? 'output' }}">{{ $title ?? 'output' }}</span>
        <div class="terminal-header-actions">
            @if(isset($subtitle))
                <span class="hidden sm:inline">{{ $subtitle }}</span>
            @endif
            @if($terminalText !== '')
                <button type="button" class="terminal-copy-btn" x-on:click="window.cipiCopy(raw).then(() => { copied = true; setTimeout(() => copied = false, 1600) })" :title="copied ? 'Copied' : 'Copy text'">
                    <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                </button>
                <button type="button" class="terminal-copy-btn" x-on:click="window.cipiCopy(md)" title="Copy as a Markdown code block">MD</button>
            @endif
        </div>
    </div>
    <div class="terminal-body {{ ($tall ?? false) ? 'tall' : '' }}" @if($autoScroll ?? true) x-init="$el.scrollTop = $el.scrollHeight" @endif>
        @forelse($terminalLines as $line)
            @php
                $lower = strtolower($line);
                $trim = ltrim($line);
                $class = match (true) {
                    str_contains($lower, '[error]') || str_contains($lower, '.error:') || str_contains($lower, 'error:') || str_contains($lower, 'fatal') || str_contains($lower, 'failed') || (bool) preg_match('/"\s(5\d\d)\s/', $line) => 'error',
                    str_contains($lower, 'warn') => 'warn',
                    str_starts_with($trim, '✓') || str_contains($lower, 'successfully') || str_contains($line, ' DONE') => 'ok',
                    str_starts_with($trim, '$ ') || str_starts_with($trim, '→') => 'prompt',
                    str_starts_with($trim, '#') || str_starts_with($trim, '--') || str_starts_with($trim, 'task ') => 'dim',
                    default => '',
                };
            @endphp
            <div class="terminal-line {{ $class }}">{{ $line === '' ? ' ' : $line }}</div>
        @empty
            <div class="terminal-line dim">No output.</div>
        @endforelse
    </div>
</div>
