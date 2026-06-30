@if(isset($servers) && $servers->isNotEmpty())
    <div class="flex items-center gap-3 mb-6">
        <label class="text-sm text-surface-400" style="margin:0;">Server:</label>
        <select wire:model.live="serverId" class="text-sm" style="width:auto;min-width:12rem;">
            @foreach($servers as $server)
                <option value="{{ $server->id }}">{{ $server->name }}</option>
            @endforeach
        </select>
    </div>
@endif
