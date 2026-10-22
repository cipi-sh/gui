{{-- Outcome of a cipi.yml apply (job app-yml-apply — API 1.35+ / Cipi 5.6.4+). Expects $ymlResult from JobOutputParser::ymlApply(). --}}
@php
    $plural = fn (int $n, string $word): string => $n.' '.\Illuminate\Support\Str::plural($word, $n);
@endphp
@switch($ymlResult['outcome'])
    @case('applied')
        <x-cipi::alert type="success" data-yml-outcome="applied">cipi.yml applied — {{ $plural($ymlResult['applied'], 'change') }}.</x-cipi::alert>
        @break
    @case('unchanged')
        <x-cipi::alert type="info" data-yml-outcome="unchanged">Nothing to apply — the server already matches cipi.yml.</x-cipi::alert>
        @break
    @case('partial')
        <x-cipi::alert type="warn" data-yml-outcome="partial" :title="'cipi.yml applied in part: '.$plural($ymlResult['failed'], 'change').' failed'">
            <p>{{ $plural($ymlResult['applied'], 'change') }} applied. Fix what failed and apply again — only what still differs is changed.</p>
            @if($ymlResult['failures'])
                <ul class="mt-2 space-y-1" style="list-style:disc;padding-left:1.1rem;">
                    @foreach($ymlResult['failures'] as $failure)<li>{{ $failure }}</li>@endforeach
                </ul>
            @endif
        </x-cipi::alert>
        @break
    @case('blocked')
        <x-cipi::alert type="danger" data-yml-outcome="blocked" title="cipi.yml not applied — resolve these first">
            <ul class="mt-1 space-y-1" style="list-style:disc;padding-left:1.1rem;">
                @foreach($ymlResult['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach
            </ul>
        </x-cipi::alert>
        @break
    @case('invalid')
        <x-cipi::alert type="danger" data-yml-outcome="invalid" title="cipi.yml is not valid — nothing was applied">
            @if($ymlResult['errors'])
                <ul class="mt-1 space-y-1 font-mono text-xs" style="list-style:disc;padding-left:1.1rem;">
                    @foreach($ymlResult['errors'] as $message)<li>{{ $message }}</li>@endforeach
                </ul>
            @endif
        </x-cipi::alert>
        @break
    @case('no-file')
        <x-cipi::alert type="warn" data-yml-outcome="no-file">There is no cipi.yml in the current release — commit one and deploy.</x-cipi::alert>
        @break
@endswitch
@if($ymlResult['errors'] && $ymlResult['outcome'] !== 'invalid')
    <x-cipi::alert type="danger" title="Errors">
        <ul class="mt-1 space-y-1" style="list-style:disc;padding-left:1.1rem;">
            @foreach($ymlResult['errors'] as $message)<li>{{ $message }}</li>@endforeach
        </ul>
    </x-cipi::alert>
@endif
