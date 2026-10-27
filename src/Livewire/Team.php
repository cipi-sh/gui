<?php

namespace CipiGui\Livewire;

use CipiGui\Models\CipiAppGrant;
use CipiGui\Models\CipiInvite;
use CipiGui\Models\CipiServer;
use CipiGui\Models\CipiServerGrant;
use CipiGui\Services\Access;
use CipiGui\Services\CipiApiClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Team (admins): who can sign in, what each user may do, and invite links.
 *
 * An admin sees and does everything. A user has a level on whole servers (view
 * or manage) and abilities on single apps, chosen here. An invite is a copyable
 * link that creates one account with a role and its grants — no email is sent.
 */
#[Layout('cipi-gui::layouts.app')]
#[Title('Team')]
class Team extends Component
{
    public const INVITE_DAYS = 7;

    // ── access editor (invite and edit share it) ──
    public string $editorRole = Access::USER;

    /** @var array<int, string> server id => '' | view | manage */
    public array $serverLevels = [];

    /** @var array<int, array<string, list<string>>> server id => app => abilities */
    public array $appGrants = [];

    /** @var array<int, list<array{app: string, domain: string}>> */
    public array $serverApps = [];

    /** @var array<int, string> */
    public array $serverAppErrors = [];

    public bool $appsLoaded = false;

    // ── invite ──
    public bool $showInvite = false;

    public string $inviteLabel = '';

    public ?string $inviteLink = null;

    // ── edit a user ──
    public ?int $editingUserId = null;

    public string $editingName = '';

    public ?int $deleteUserId = null;

    public function mount(): void
    {
        abort_unless(Access::isAdmin(Auth::user()), 403);
    }

    // ── invites ──────────────────────────────────────────────────────

    public function openInvite(): void
    {
        $this->resetEditor();
        $this->inviteLabel = '';
        $this->inviteLink = null;
        $this->showInvite = true;
        $this->loadServerApps();
    }

    public function closeInvite(): void
    {
        $this->showInvite = false;
        $this->inviteLink = null;
    }

    public function createInvite(): void
    {
        $this->validate([
            'inviteLabel' => ['nullable', 'string', 'max:80'],
            'editorRole' => ['required', 'in:admin,user'],
        ]);

        [$servers, $apps] = $this->editorRole === Access::ADMIN ? [[], []] : Access::normalizeGrants($this->serverLevels, $this->appGrants);

        $invite = CipiInvite::create([
            'token' => Str::random(48),
            'label' => trim($this->inviteLabel) !== '' ? trim($this->inviteLabel) : null,
            'role' => $this->editorRole,
            'server_grants' => $servers,
            'app_grants' => $apps,
            'created_by' => Auth::id(),
            'expires_at' => now()->addDays(self::INVITE_DAYS),
        ]);

        $this->inviteLink = route('cipi-gui.invite', ['token' => $invite->token]);
        $this->dispatch('notify', type: 'success', message: 'Invite link created. Copy it and send it yourself.');
    }

    public function revokeInvite(int $id): void
    {
        CipiInvite::query()->whereKey($id)->whereNull('accepted_at')->delete();
        $this->dispatch('notify', type: 'info', message: 'Invite revoked.');
    }

    // ── users ────────────────────────────────────────────────────────

    public function openEdit(int $userId): void
    {
        $user = $this->userModel()::query()->find($userId);
        if (! $user) {
            return;
        }

        $this->resetEditor();
        $this->editingUserId = $userId;
        $this->editingName = (string) ($user->name ?? $user->email);
        $this->editorRole = Access::role($user);

        $grants = Access::grantsFor($user);
        foreach ($grants['servers'] as $serverId => $level) {
            $this->serverLevels[$serverId] = $level;
        }
        foreach ($grants['apps'] as $serverId => $apps) {
            foreach ($apps as $app => $abilities) {
                $this->appGrants[$serverId][$app] = $abilities;
            }
        }

        $this->loadServerApps();
    }

    public function closeEdit(): void
    {
        $this->editingUserId = null;
    }

    public function saveAccess(): void
    {
        $user = $this->editingUserId ? $this->userModel()::query()->find($this->editingUserId) : null;
        if (! $user) {
            return;
        }

        $this->validate(['editorRole' => ['required', 'in:admin,user']]);

        $self = (int) $user->getKey() === (int) Auth::id();
        if ($self && $this->editorRole !== Access::ADMIN) {
            $this->addError('editorRole', 'You cannot take the admin role away from yourself.');

            return;
        }

        if (Schema::hasColumn($user->getTable(), 'role')) {
            $user->forceFill(['role' => $this->editorRole])->save();
        }

        if ($this->editorRole === Access::USER) {
            Access::replaceGrants((int) $user->getKey(), $this->serverLevels, $this->appGrants);
        }

        Access::flush();
        $this->editingUserId = null;
        $this->dispatch('notify', type: 'success', message: "Access of {$this->editingName} saved.");
    }

    public function confirmDelete(int $userId): void
    {
        if ($userId === (int) Auth::id()) {
            $this->dispatch('notify', type: 'error', message: 'You cannot remove your own account.');

            return;
        }

        $this->deleteUserId = $userId;
    }

    public function cancelDelete(): void
    {
        $this->deleteUserId = null;
    }

    public function deleteUser(): void
    {
        $id = $this->deleteUserId;
        $this->deleteUserId = null;

        if (! $id || $id === (int) Auth::id()) {
            return;
        }

        $user = $this->userModel()::query()->find($id);
        if (! $user) {
            return;
        }

        CipiServerGrant::query()->where('user_id', $id)->delete();
        CipiAppGrant::query()->where('user_id', $id)->delete();
        $name = (string) ($user->name ?? $user->email);
        $user->delete();
        Access::flush();

        $this->dispatch('notify', type: 'success', message: "{$name} removed.");
    }

    // ── editor ───────────────────────────────────────────────────────

    protected function resetEditor(): void
    {
        $this->resetErrorBag();
        $this->editorRole = Access::USER;
        $this->serverLevels = [];
        $this->appGrants = [];
        foreach (CipiServer::query()->where('is_active', true)->pluck('id') as $id) {
            $this->serverLevels[(int) $id] = '';
        }
    }

    /** The apps of every server, for the per-app checkboxes. One request per server, concurrently. */
    public function loadServerApps(): void
    {
        $servers = CipiServer::query()->where('is_active', true)->orderBy('name')->get();
        $this->serverApps = [];
        $this->serverAppErrors = [];

        foreach (CipiApiClient::appsForMany($servers) as $serverId => $result) {
            if ($result['apps'] === null) {
                $this->serverAppErrors[$serverId] = (string) $result['error'];
                $this->serverApps[$serverId] = [];
            } else {
                $this->serverApps[$serverId] = array_values(array_map(
                    fn (array $app) => ['app' => (string) $app['app'], 'domain' => (string) ($app['domain'] ?? '')],
                    array_filter($result['apps'], fn ($app) => is_array($app) && isset($app['app'])),
                ));
            }

            // Granted apps the server no longer lists stay editable.
            $listed = array_column($this->serverApps[$serverId], 'app');
            foreach (array_keys($this->appGrants[$serverId] ?? []) as $app) {
                if (! in_array($app, $listed, true)) {
                    $this->serverApps[$serverId][] = ['app' => (string) $app, 'domain' => 'not on the server any more'];
                }
            }

            foreach ($this->serverApps[$serverId] as $app) {
                $this->appGrants[$serverId][$app['app']] ??= [];
            }
        }

        $this->appsLoaded = true;
    }

    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    protected function userModel(): string
    {
        return config('auth.providers.users.model', 'App\\Models\\User');
    }

    public function render()
    {
        $me = (int) Auth::id();
        $users = $this->userModel()::query()->orderBy('name')->get()->map(fn ($user) => [
            'id' => (int) $user->getKey(),
            'name' => (string) ($user->name ?? ''),
            'email' => (string) ($user->email ?? ''),
            'role' => Access::role($user),
            'two_factor' => (bool) ($user->two_factor_enabled ?? false),
            'access' => Access::summary($user),
            'self' => (int) $user->getKey() === $me,
        ])->all();

        $invites = CipiInvite::query()->pending()->orderByDesc('created_at')->get();
        $servers = CipiServer::query()->where('is_active', true)->orderBy('name')->get();

        return view('cipi-gui::livewire.team', [
            'users' => $users,
            'invites' => $invites,
            'servers' => $servers,
            'abilities' => Access::ABILITY_LABELS,
            'levels' => Access::SERVER_LEVELS,
            'inviteDays' => self::INVITE_DAYS,
            'deleting' => $this->deleteUserId ? collect($users)->firstWhere('id', $this->deleteUserId) : null,
        ]);
    }
}
