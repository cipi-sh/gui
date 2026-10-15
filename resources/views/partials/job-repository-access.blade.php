{{-- Repository access check and build command from an app create / app edit result (API 1.34+ / Cipi 5.6.0+). --}}
@php
    $repoAccess = $this->jobRepositoryAccess();
    $jobBuild = $this->jobBuild();
@endphp
@if($repoAccess)
    @if($repoAccess['status'] === 'ok')
        <x-cipi::alert type="success" data-repository-access="ok">
            Repository reachable{!! $repoAccess['branch'] ? ' · branch <code>'.e($repoAccess['branch']).'</code>' : '' !!} — the deploy key can read it.
        </x-cipi::alert>
    @elseif($repoAccess['status'] === 'nobranch')
        <x-cipi::alert type="warn" data-repository-access="nobranch"
                       :title="$repoAccess['branch'] ? 'Repository reachable, but it has no branch “'.$repoAccess['branch'].'”' : 'Repository reachable, but the branch does not exist'">
            <p>Deploys fail until the app points at an existing branch: change it under Overview → Configuration{{ $repoAccess['hint'] ? ', or on the server over SSH:' : '.' }}</p>
            @if($repoAccess['hint'])
                <x-cipi::secret label="Run on the server" :value="$repoAccess['hint']" />
            @endif
        </x-cipi::alert>
    @else
        <x-cipi::alert type="warn" data-repository-access="denied" title="The deploy key cannot read the repository">
            <p>The first deploy will fail until this is fixed. The app itself was {{ ($activeJobType ?? '') === 'app-edit' ? 'updated' : 'created' }}.</p>
            @if($repoAccess['error'])
                <p class="mt-2">Git said: <code>{{ $repoAccess['error'] }}</code></p>
            @endif
            @if($repoAccess['hint'])
                <p class="mt-2">The API cannot save Git tokens. Fix it on the server over SSH:</p>
                <x-cipi::secret label="Run on the server" :value="$repoAccess['hint']" />
            @else
                <p class="mt-2">Add the deploy key to the repository, then check again on the server with <code>cipi git check{{ is_string($activeJobResult['app'] ?? null) ? ' '.$activeJobResult['app'] : '' }}</code>.</p>
            @endif
        </x-cipi::alert>
    @endif
@endif
@if($jobBuild)
    <p class="text-xs text-muted flex flex-wrap items-center gap-2" data-job-build>
        <x-cipi::icon name="package" class="h-3.5 w-3.5" />
        <span>Build command <code>{{ $jobBuild }}</code> — runs on every deploy, before the release goes live.</span>
    </p>
@endif
