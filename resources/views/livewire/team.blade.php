<div>
    <x-cipi::page-header title="Team" subtitle="Who signs in to this panel and what each account may do. Admins see and do everything; users only what they are given.">
        <x-slot:actions>
            <button type="button" wire:click="openInvite" class="btn btn-primary"><x-cipi::icon name="plus" /> Invite</button>
        </x-slot:actions>
    </x-cipi::page-header>

    <div class="card card-flush">
        <div class="table-scroll">
            <table>
                <thead>
                    <tr><th>Account</th><th>Role</th><th>2FA</th><th>Access</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                    @foreach($users as $user)
                        <tr wire:key="user-{{ $user['id'] }}">
                            <td>
                                <p class="font-semibold text-strong">{{ $user['name'] ?: $user['email'] }} @if($user['self'])<span class="badge badge-neutral ml-1">you</span>@endif @if($user['sso'])<span class="badge badge-blue ml-1" title="Signs in through the identity provider">SSO</span>@endif</p>
                                <p class="text-xs text-muted">{{ $user['email'] }}</p>
                            </td>
                            <td><span class="badge {{ $user['role'] === 'admin' ? 'badge-accent' : 'badge-neutral' }}">{{ $user['role'] === 'admin' ? 'Admin' : 'User' }}</span></td>
                            <td><span class="badge {{ $user['two_factor'] ? 'badge-green' : 'badge-amber' }}">{{ $user['two_factor'] ? 'On' : 'Off' }}</span></td>
                            <td class="text-soft">{{ $user['access'] }}</td>
                            <td>
                                <div class="btn-actions">
                                    <button type="button" wire:click="openEdit({{ $user['id'] }})" class="btn btn-secondary btn-sm">{{ $user['self'] ? 'Your access' : 'Edit access' }}</button>
                                    @unless($user['self'])
                                        <button type="button" wire:click="confirmDelete({{ $user['id'] }})" class="btn btn-ghost btn-sm danger">Remove</button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card card-flush mt-6">
        <div class="card-header p-5 mb-0">
            <div>
                <h2 class="card-title">Pending invites</h2>
                <p class="card-subtitle">A link creates one account with the role and access chosen when it was made. Links expire after {{ $inviteDays }} days; nothing is emailed — copy and send them yourself.</p>
            </div>
        </div>
        @if($invites->isEmpty())
            <div class="px-5 pb-5"><p class="text-muted">No open invites.</p></div>
        @else
            <div class="table-scroll">
                <table class="table-compact">
                    <thead><tr><th>Invite</th><th>Role</th><th>Access</th><th>Expires</th><th class="text-right">Actions</th></tr></thead>
                    <tbody>
                        @foreach($invites as $invite)
                            @php
                                $grantServers = count((array) ($invite->server_grants ?? []));
                                $grantApps = array_sum(array_map('count', (array) ($invite->app_grants ?? [])));
                                $parts = [];
                                if ($grantServers) { $parts[] = $grantServers.' '.\Illuminate\Support\Str::plural('server', $grantServers); }
                                if ($grantApps) { $parts[] = $grantApps.' '.\Illuminate\Support\Str::plural('app', $grantApps); }
                                $accessLabel = $invite->role === 'admin' ? 'Every server' : ($parts ? implode(' · ', $parts) : 'No access yet');
                            @endphp
                            <tr wire:key="invite-{{ $invite->id }}">
                                <td class="text-strong">{{ $invite->label ?: 'Invite #'.$invite->id }}<span class="block text-2xs text-subtle">created {{ $invite->created_at?->diffForHumans() }}</span></td>
                                <td><span class="badge {{ $invite->role === 'admin' ? 'badge-accent' : 'badge-neutral' }}">{{ ucfirst($invite->role) }}</span></td>
                                <td class="text-xs text-soft">{{ $accessLabel }}</td>
                                <td class="text-xs text-muted whitespace-nowrap">{{ $invite->expires_at->diffForHumans() }}</td>
                                <td>
                                    <div class="btn-actions">
                                        <x-cipi::copy :value="route('cipi-gui.invite', ['token' => $invite->token])" label="Copy link" />
                                        <button type="button" wire:click="revokeInvite({{ $invite->id }})" wire:confirm="Revoke this invite? The link stops working." class="btn btn-ghost btn-sm danger">Revoke</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if($showInvite)
        <x-cipi::modal title="Invite someone" close="closeInvite" size="lg" persistent subtitle="Pick the role and the access, then copy the link. It works once and expires after {{ $inviteDays }} days.">
            @if($inviteLink)
                <div class="modal-body space-y-4">
                    <x-cipi::alert type="success" title="Invite link ready">Send it to the person yourself — by chat, email, however you like. Whoever opens it first creates the account.</x-cipi::alert>
                    <div class="card p-4" style="border-color: var(--accent-line);">
                        <x-cipi::secret label="Invite link" :value="$inviteLink" />
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" wire:click="closeInvite" class="btn btn-primary">Done</button>
                </div>
            @else
                <form wire:submit="createInvite">
                    <div class="modal-body space-y-5">
                        <div>
                            <label for="invite-label">Who is it for <span class="text-subtle font-normal">(optional note, shown on the sign-up page)</span></label>
                            <input id="invite-label" type="text" wire:model="inviteLabel" placeholder="Mario — Acme agency" autocomplete="off" autofocus>
                            @error('inviteLabel') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        @include('cipi-gui::partials.access-editor')
                    </div>
                    <div class="modal-footer">
                        <button type="button" wire:click="closeInvite" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary"><x-cipi::icon name="link" /> Create invite link</button>
                    </div>
                </form>
            @endif
        </x-cipi::modal>
    @endif

    @if($editingUserId)
        <x-cipi::modal :title="'Access of '.$editingName" close="closeEdit" size="lg" persistent subtitle="Changes apply on the next page the person opens.">
            <form wire:submit="saveAccess">
                <div class="modal-body space-y-5">
                    @if($editingUserId === auth()->id())
                        <x-cipi::alert type="info">This is your own account: an admin cannot take the admin role away from themselves, nor remove their own account.</x-cipi::alert>
                    @endif
                    @include('cipi-gui::partials.access-editor', ['lockRole' => $editingUserId === auth()->id()])
                </div>
                <div class="modal-footer">
                    <button type="button" wire:click="closeEdit" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save access</button>
                </div>
            </form>
        </x-cipi::modal>
    @endif

    @if($deleting)
        <x-cipi::modal title="Remove account" close="cancelDelete">
            <div class="modal-body">
                <p class="text-soft">Remove <strong class="text-strong">{{ $deleting['name'] ?: $deleting['email'] }}</strong> from this panel? They can no longer sign in. Nothing changes on the servers.</p>
            </div>
            <div class="modal-footer">
                <button type="button" wire:click="cancelDelete" class="btn btn-secondary">Cancel</button>
                <button type="button" wire:click="deleteUser" class="btn btn-danger-solid">Remove</button>
            </div>
        </x-cipi::modal>
    @endif
</div>
