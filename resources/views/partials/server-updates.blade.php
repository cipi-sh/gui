{{-- Server → Updates: how the server updates Cipi itself. Both safeguards are off unless turned on. --}}
@php
    $up = $updatePolicy ?? [];
    $upSigners = (array) ($up['signers'] ?? []);
    $upShipped = (array) ($up['shipped_signers'] ?? []);
    $upReady = (bool) ($up['signers_ready'] ?? false);
    $upPending = $up['pending'] ?? null;
    $upHeld = $up['held'] ?? null;
    $upWhen = fn ($t) => is_numeric($t) ? \Illuminate\Support\Carbon::createFromTimestamp((int) $t)->diffForHumans() : '';
@endphp
<div class="grid grid-cols-1 lg:grid-cols-5 gap-4" data-updates>
    <div class="lg:col-span-3 space-y-4">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title flex items-center gap-2"><x-cipi::icon name="shield" /> How this server updates Cipi</h2>
                    <p class="card-subtitle">Every night the server installs the latest Cipi from GitHub. That stays as it is unless you add one of these safeguards — so that an account that is taken over cannot reach every server within a day.</p>
                </div>
            </div>
            @if($updatePolicyUnsupported)
                <x-cipi::alert type="warning" data-updates-unsupported>{{ $updatePolicyUnsupported }}</x-cipi::alert>
            @elseif($updatePolicy === null)
                <p class="text-muted">Loading…</p>
            @else
                <form wire:submit="saveUpdatePolicy" class="space-y-5">
                    <div data-updates-verify>
                        <label class="check-card">
                            <input type="checkbox" wire:model.live="upVerify" @disabled(! $canManage)>
                            <span>
                                <span class="check-card-title">Install only signed releases</span>
                                <span class="check-card-text">A release goes in only when its tag is signed by a key this server trusts, and points at exactly what was downloaded. A release that is not is not installed, and you are notified.</span>
                            </span>
                        </label>
                        @if($upVerify && ! $upReady)
                            <div class="mt-3 p-4 rounded-lg bg-raised space-y-2" data-updates-first-trust>
                                <p class="text-sm text-soft"><strong class="text-strong">The first trust is yours.</strong> These are the keys that came with the installed release. Compare a fingerprint with the one published at <a href="https://cipi.sh/docs/self-update#signers" target="_blank" rel="noopener" class="text-link">cipi.sh/docs/self-update <x-cipi::icon name="external" /></a>, and paste it below.</p>
                                @forelse($upShipped as $s)
                                    <p class="font-mono text-xs"><span class="text-strong">{{ $s['fingerprint'] ?? '' }}</span> <span class="text-muted">{{ $s['principal'] ?? '' }}</span></p>
                                @empty
                                    <p class="text-sm text-warn">This release ships no key yet: signed releases cannot be required until it does.</p>
                                @endforelse
                                <div>
                                    <label for="up-fingerprint">The fingerprint you compared</label>
                                    <input id="up-fingerprint" type="text" wire:model="upFingerprint" class="font-mono text-sm" placeholder="SHA256:…" autocomplete="off" spellcheck="false">
                                    @error('upFingerprint') <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @endif
                    </div>

                    <div data-updates-delay>
                        <label for="up-delay">Wait before installing a new release</label>
                        <select id="up-delay" wire:model.live="upDelay" @disabled(! $canManage)>
                            @foreach(\CipiGui\Support\UpdatePolicy::DELAYS as $h => $label)
                                <option value="{{ $h }}">{{ $label }}{{ (int) $h === (int) ($up['delay_default_hours'] ?? 12) ? ' — recommended' : '' }}</option>
                            @endforeach
                        </select>
                        @error('upDelay') <p class="field-error">{{ $message }}</p> @enderror
                        <p class="field-hint">A new release is installed once this server has seen it for that long: time for a bad release to be noticed and taken back before it lands here. The clock is the server's own — a date in a commit can be forged — and a release that changes starts its wait again. While a delay is on, the server checks every hour, so a release goes in once it is due.</p>
                    </div>

                    @if($upRefused)
                        <x-cipi::alert type="danger" title="Refused — nothing changed" data-updates-refused><span class="text-sm">{{ $upRefused }}</span></x-cipi::alert>
                    @endif
                    @if($canManage)
                        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="saveUpdatePolicy" data-updates-save><x-cipi::icon name="check" /> Save</button>
                    @else
                        <p class="field-hint">Changing it needs Manage on this server.</p>
                    @endif
                </form>
            @endif
        </div>
    </div>

    <div class="lg:col-span-2 space-y-4">
        @if($updatePolicy !== null)
            <div class="card" data-updates-state>
                <div class="card-header"><h2 class="card-title">Now</h2></div>
                <dl class="kv">
                    <dt>Signature</dt><dd>{{ ! empty($up['verify']) ? 'required' : 'not checked' }}</dd>
                    <dt>Delay</dt><dd>{{ (int) ($up['delay_hours'] ?? 0) > 0 ? ((int) $up['delay_hours']).' hours' : 'none' }}</dd>
                    <dt>Trusted keys</dt>
                    <dd>
                        @forelse($upSigners as $s)
                            <span class="font-mono text-xs block">{{ $s['fingerprint'] ?? '' }} <span class="text-muted">{{ $s['principal'] ?? '' }}</span></span>
                        @empty
                            <span class="text-muted">none yet</span>
                        @endforelse
                    </dd>
                    @if(is_array($upPending))
                        <dt>Waiting</dt><dd data-updates-pending>{{ ! empty($upPending['version']) ? 'v'.$upPending['version'].' ' : '' }}<span class="font-mono text-xs">{{ substr((string) ($upPending['sha'] ?? ''), 0, 12) }}</span>, seen {{ $upWhen($upPending['first_seen'] ?? null) }}</dd>
                    @endif
                    @if(is_array($upHeld))
                        <dt>Last held</dt><dd data-updates-held><span class="text-sm">{{ $upHeld['reason'] ?? '' }}</span> <span class="text-muted text-xs">{{ $upWhen($upHeld['at'] ?? null) }}</span></dd>
                    @endif
                </dl>
            </div>
        @endif
        <div class="card">
            <div class="card-header"><h2 class="card-title">On the server</h2></div>
            <div class="space-y-2 text-sm text-soft">
                <p><code>cipi self-update policy</code> shows the same. <code>cipi self-update --now</code> installs a release held by the delay at once — the signature is still checked.</p>
                <p>Starting an update is not done from the panel: it stays the server's, at its shell or every night.</p>
            </div>
        </div>
    </div>
</div>
