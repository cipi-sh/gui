<?php

namespace CipiGui\Livewire;

use CipiGui\Models\CipiAppGrant;
use CipiGui\Models\CipiInvite;
use CipiGui\Models\CipiServer;
use CipiGui\Models\CipiServerGrant;
use CipiGui\Services\Access;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\TwoFactorService;
use CipiGui\Support\Panel;
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
 *
 * Two-factor authentication (4.6.0): an admin can require it for every account
 * — whoever has none is taken to its setup before anything else, at sign-in
 * and at the next page of a session already open — and reset another account's
 * 2FA (a lost phone). Sign-ins through SSO are left to the identity provider.
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

    public ?int $resetTwoFactorUserId = null;

    /**
     * Every request, not only the first: the route's admin middleware covers
     * the page load, and an account that stopped being an admin could go on
     * acting from a page it still had open (4.2.0).
     */
    public function boot(): void
    {
        abort_unless(Access::isAdmin(Auth::user()), 403, 'Only admins can open this page.');
    }

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

    // ── two-factor authentication ────────────────────────────────────

    /**
     * Require 2FA for every account, or stop requiring it. Turning it on needs
     * the admin's own session to pass it — 2FA on, or signed in through SSO —
     * or the next page would take them to the setup as well.
     */
    public function setTwoFactorRequired(bool $required): void
    {
        $this->resetErrorBag('twoFactor');

        if ($required && TwoFactorService::setupRequiredIfEnforced(Auth::user(), session()->driver())) {
            $this->addError('twoFactor', 'Turn on two-factor authentication for your own account first (Settings): requiring it would take you to its setup too.');
            $this->dispatch('notify', type: 'error', message: 'Turn on your own 2FA first, in Settings.');

            return;
        }

        Panel::set(Panel::ENFORCE_2FA, $required ? '1' : '0');

        if (! $required) {
            $this->dispatch('notify', type: 'info', message: 'Two-factor authentication is optional again. Accounts that have it keep it.');

            return;
        }

        $pending = $this->userModel()::query()->get()->filter(fn ($u) => ! TwoFactorService::hasSecondFactor($u))->count();
        $this->dispatch('notify', type: 'success', message: $pending === 0
            ? 'Two-factor authentication is required. Every account already has it.'
            : 'Two-factor authentication is required. '.$pending.' '.Str::plural('account', $pending).' without it '.($pending === 1 ? 'sets' : 'set').' it up at the next page.');
    }

    public function confirmResetTwoFactor(int $userId): void
    {
        if ($userId === (int) Auth::id()) {
            $this->dispatch('notify', type: 'error', message: 'Your own 2FA is in Settings.');

            return;
        }

        $this->resetTwoFactorUserId = $userId;
    }

    public function cancelResetTwoFactor(): void
    {
        $this->resetTwoFactorUserId = null;
    }

    /** A lost phone: the account's 2FA is removed; where it is required, it is set up again at the next sign-in. */
    public function resetTwoFactor(TwoFactorService $twoFactor): void
    {
        $id = $this->resetTwoFactorUserId;
        $this->resetTwoFactorUserId = null;

        if (! $id || $id === (int) Auth::id()) {
            return;
        }

        $user = $this->userModel()::query()->find($id);
        if (! $user) {
            return;
        }

        $twoFactor->disable($user);
        // A lost phone takes its passkeys with it: they go too (4.8.0).
        try {
            \CipiGui\Models\CipiPasskey::query()->where('user_id', $user->getKey())->delete();
        } catch (\Throwable) {
            // Before the migration: no passkeys to remove.
        }
        $name = (string) ($user->name ?: $user->email);

        $this->dispatch('notify', type: 'success', message: Panel::enforceTwoFactor()
            ? "2FA of {$name} reset: they set it up again before anything else."
            : "2FA of {$name} turned off: they can set it up again in Settings.");
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
            'two_factor' => TwoFactorService::hasSecondFactor($user),
            'sso' => filled($user->sso_subject ?? null),
            'access' => Access::summary($user),
            'self' => (int) $user->getKey() === $me,
        ])->all();

        $required = Panel::enforceTwoFactor();
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
            'resetting' => $this->resetTwoFactorUserId ? collect($users)->firstWhere('id', $this->resetTwoFactorUserId) : null,
            'twoFactorRequired' => $required,
            'withoutTwoFactor' => collect($users)->reject(fn ($u) => $u['two_factor'])->values()->all(),
        ]);
    }
}
