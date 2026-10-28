<nav class="flex-1 overflow-y-auto px-3 pb-4">
    @foreach($links as $link)
        @if(isset($link['section']))
            <p class="nav-section" wire:key="nav-{{ $link['section'] }}">{{ $link['section'] }}</p>
        @else
            <a href="{{ $link['href'] }}" wire:key="nav-{{ $link['href'] }}" class="nav-link {{ $link['active'] ? 'active' : '' }}" @if(isset($link['title'])) title="{{ $link['title'] }}" @endif
               @if($link['active']) aria-current="page" @endif
               @if($mobile) x-on:click="mobileNavOpen = false" @endif>
                <x-cipi::icon :name="$link['icon']" />
                <span class="truncate">{{ $link['label'] }}</span>
                @if(isset($link['dot']))<span class="dot {{ $link['dot'] }} ml-auto" style="width:.4rem;height:.4rem;box-shadow:none"></span>@endif
                @if(isset($link['meta']))<span class="nav-meta truncate">{{ $link['meta'] }}</span>@endif
            </a>
        @endif
    @endforeach
</nav>
