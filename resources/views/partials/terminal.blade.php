@php
    $terminalLines = $lines ?? [];
    $terminalText = implode("\n", $terminalLines);
    $terminalMd = "```text\n".$terminalText.($terminalText !== '' ? "\n" : '')."```";
@endphp
<div
    class="terminal"
    x-data="{
        copied: false,
        md: {{ \Illuminate\Support\Js::from($terminalMd) }},
        async copyMd() {
            try {
                await navigator.clipboard.writeText(this.md);
            } catch (e) {
                const ta = document.createElement('textarea');
                ta.value = this.md;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }
            this.copied = true;
            window.dispatchEvent(new CustomEvent('notify', { detail: { type: 'success', message: 'Copied as Markdown' } }));
            setTimeout(() => this.copied = false, 2000);
        }
    }"
>
    <div class="terminal-header">
        <div class="terminal-dot" style="background:#ff5f57;"></div>
        <div class="terminal-dot" style="background:#febc2e;"></div>
        <div class="terminal-dot" style="background:#28c840;"></div>
        <span class="text-xs text-surface-400 ml-2">{{ $title ?? 'logs' }}</span>
        <div class="terminal-header-actions">
            @if(isset($subtitle))
                <span class="text-xs text-surface-500">{{ $subtitle }}</span>
            @endif
            @if($terminalText !== '')
                <button
                    type="button"
                    class="terminal-copy-btn"
                    @click="copyMd()"
                    :title="copied ? 'Copied!' : 'Copy as Markdown'"
                >
                    <span x-text="copied ? 'Copied!' : 'Copy MD'"></span>
                </button>
            @endif
        </div>
    </div>
    <div class="terminal-body" @if($autoScroll ?? true) x-init="$el.scrollTop = $el.scrollHeight" @endif>
        @forelse($terminalLines as $line)
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
