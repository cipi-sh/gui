<?php

namespace CipiGui\Livewire;

use CipiGui\Models\CipiPasskey;
use CipiGui\Services\Passkeys;
use CipiGui\Support\Panel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Settings → Passkeys: the account's passkeys, named, renamed and removed.
 * They are added by the page's script (the browser makes the key), which
 * then refreshes this list. Every account has its own, admins included.
 */
class PasskeyList extends Component
{
    public string $newName = '';

    public ?int $renaming = null;

    public string $renameTo = '';

    #[On('passkeys-changed')]
    public function refreshList(): void
    {
        $this->newName = '';
    }

    public function startRename(int $id): void
    {
        $passkey = $this->mine($id);
        $this->renaming = $passkey?->id;
        $this->renameTo = (string) $passkey?->name;
    }

    public function rename(): void
    {
        $this->validate(['renameTo' => ['required', 'string', 'max:80']], [], ['renameTo' => 'name']);
        $passkey = $this->renaming !== null ? $this->mine($this->renaming) : null;
        $passkey?->forceFill(['name' => trim($this->renameTo)])->save();
        $this->reset('renaming', 'renameTo');
    }

    public function cancelRename(): void
    {
        $this->reset('renaming', 'renameTo');
    }

    public function remove(int $id): void
    {
        $passkey = $this->mine($id) ?? abort(404);
        $user = Auth::user();

        // Required for everyone (Team): the last second factor stays until there is another.
        $others = CipiPasskey::query()->where('user_id', $user->getAuthIdentifier())->whereKeyNot($passkey->id)->exists();
        if (Panel::enforceTwoFactor() && ! ($user->two_factor_enabled ?? false) && ! $others) {
            $this->dispatch('notify', type: 'error', message: 'An admin requires two-factor authentication: add another passkey or set up the authenticator app before removing the last one.');

            return;
        }

        $passkey->delete();
        Log::info('cipi-gui.passkey', ['user' => $user->getAuthIdentifier(), 'passkey' => $id, 'action' => 'removed']);
        $this->dispatch('notify', type: 'success', message: 'Passkey removed. Delete it from the device too (Passwords, or the password manager) — it no longer opens this panel.');
    }

    private function mine(int $id): ?CipiPasskey
    {
        return CipiPasskey::query()->where('user_id', Auth::id())->whereKey($id)->first();
    }

    public function render()
    {
        $ready = Passkeys::ready();

        return view('cipi-gui::livewire.passkey-list', [
            'enabled' => Passkeys::enabled() && $ready,
            'passkeys' => $ready ? CipiPasskey::query()->where('user_id', Auth::id())->orderBy('created_at')->get() : collect(),
            'rpId' => Passkeys::rpId(),
        ]);
    }
}
