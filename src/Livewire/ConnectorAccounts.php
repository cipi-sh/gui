<?php

namespace CipiGui\Livewire;

use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\ConnectorException;
use CipiGui\Infrastructure\Connectors;
use CipiGui\Infrastructure\Field;
use CipiGui\Models\CipiConnectorAccount;
use CipiGui\Services\Access;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Settings → Connectors (admins): the accounts of each provider, with where
 * to make the credentials and the permissions they need. Each account is
 * checked against the provider before it is saved; the credentials are kept
 * encrypted and never sent back to the page — a secret left empty keeps the
 * stored one. A connector with an account shows in the sidebar.
 */
class ConnectorAccounts extends Component
{
    /** The connector whose dialog is open. */
    public ?string $managing = null;

    /** uuid of the account in the form; 'new' for a new one */
    public ?string $editing = null;

    public string $accountName = '';

    /** @var array<string, mixed> */
    public array $credentials = [];

    public function hydrate(): void
    {
        abort_unless(Access::isAdmin(Auth::user()), 403);
    }

    public function definition(): ?Connector
    {
        return $this->managing !== null ? Connectors::find($this->managing) : null;
    }

    public function open(string $key): void
    {
        abort_unless(Access::isAdmin(Auth::user()) && Connectors::find($key) !== null, 404);

        $this->managing = $key;
        $none = CipiConnectorAccount::query()->where('connector', $key)->doesntExist();
        $this->editing = $none ? 'new' : null;
        $this->fillForm(null);
    }

    public function close(): void
    {
        $this->reset('managing', 'editing', 'accountName', 'credentials');
        $this->resetValidation();
    }

    public function edit(?string $uuid = null): void
    {
        $account = $uuid !== null ? $this->account($uuid) : null;
        $this->editing = $account->uuid ?? 'new';
        $this->fillForm($account);
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->resetValidation();
    }

    private function account(string $uuid): ?CipiConnectorAccount
    {
        return CipiConnectorAccount::query()->where('connector', $this->managing)->where('uuid', $uuid)->first();
    }

    private function fillForm(?CipiConnectorAccount $account): void
    {
        $this->accountName = $account->name ?? '';
        $this->credentials = [];

        foreach ($this->definition()?->fields() ?? [] as $field) {
            $stored = $account?->credential($field->name);
            $default = $field->defaultFor([]);
            $this->credentials[$field->name] = $field->secret
                ? ''
                : (is_scalar($stored) ? (string) $stored : (is_scalar($default) ? (string) $default : ($field->type === 'checkbox' ? false : '')));
        }

        $this->resetValidation();
    }

    public function save(): void
    {
        $definition = $this->definition() ?? abort(404);
        $account = $this->editing !== null && $this->editing !== 'new' ? $this->account($this->editing) : null;
        $fields = $definition->fields();

        $this->validate([
            'accountName' => ['required', 'string', 'max:80', Rule::unique('cipi_connector_accounts', 'name')->where('connector', $definition->key())->ignore($account?->id)],
            ...collect($fields)->mapWithKeys(fn (Field $field) => ['credentials.'.$field->name => $field->validation(keepsStoredSecret: $account !== null)])->all(),
        ], [], [
            'accountName' => 'name',
            ...collect($fields)->mapWithKeys(fn (Field $field) => ['credentials.'.$field->name => mb_strtolower($field->label)])->all(),
        ]);

        $credentials = $account->credentials ?? [];
        foreach ($fields as $field) {
            $value = $field->cast($this->credentials[$field->name] ?? null);
            if ($field->secret && $value === null && $account !== null) {
                continue;
            }
            $credentials[$field->name] = $value;
        }

        try {
            $status = $definition->verify($definition->client($credentials));
        } catch (ConnectorException $e) {
            $this->addError('credentials', 'The provider refused these credentials: '.$e->getMessage());

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->addError('credentials', 'The panel could not make sense of the answer: '.$e->getMessage());

            return;
        }

        $account ??= new CipiConnectorAccount(['connector' => $definition->key()]);
        $account->fill(['name' => trim($this->accountName), 'credentials' => $credentials, 'status' => mb_substr($status, 0, 250), 'checked_at' => now()])->save();
        $account->forget();

        Log::info('cipi-gui.infrastructure', ['user' => Auth::id(), 'connector' => $definition->key(), 'account' => $account->uuid, 'action' => 'account-saved']);

        $this->editing = null;
        $this->credentials = array_map(fn ($v) => '', $this->credentials);
        $this->dispatch('connectors-changed');
        $this->dispatch('notify', type: 'success', message: 'Account saved: '.$status);
    }

    public function test(string $uuid): void
    {
        $account = $this->account($uuid) ?? abort(404);

        try {
            $status = $account->definition()->verify($account->client());
            $this->dispatch('notify', type: 'success', message: $status);
        } catch (\Throwable $e) {
            $status = $e instanceof ConnectorException ? $e->getMessage() : 'The panel could not make sense of the answer: '.$e->getMessage();
            $this->dispatch('notify', type: 'error', message: $status);
        }

        $account->forceFill(['status' => mb_substr($status, 0, 250), 'checked_at' => now()])->save();
    }

    public function remove(string $uuid): void
    {
        $account = $this->account($uuid);
        $account?->delete();

        if ($account !== null) {
            Log::info('cipi-gui.infrastructure', ['user' => Auth::id(), 'connector' => $account->connector, 'account' => $account->uuid, 'action' => 'account-removed']);
            $this->dispatch('connectors-changed');
            $this->dispatch('notify', type: 'success', message: 'Account removed from the panel. Its credentials still work at the provider until you revoke them there.');
        }

        if (CipiConnectorAccount::query()->where('connector', $this->managing)->doesntExist()) {
            $this->editing = 'new';
            $this->fillForm(null);
        }
    }

    public function render()
    {
        $counts = CipiConnectorAccount::query()->selectRaw('connector, count(*) as n')->groupBy('connector')->pluck('n', 'connector')->all();

        return view('cipi-gui::livewire.connector-accounts', [
            'groups' => Connectors::grouped(),
            'counts' => $counts,
            'definition' => $this->definition(),
            'accounts' => $this->managing !== null
                ? CipiConnectorAccount::query()->where('connector', $this->managing)->orderBy('name')->get()
                : collect(),
        ]);
    }
}
