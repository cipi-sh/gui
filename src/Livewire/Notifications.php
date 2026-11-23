<?php

namespace CipiGui\Livewire;

use CipiGui\Livewire\Concerns\InteractsWithCipiServer;
use CipiGui\Services\Access;
use CipiGui\Services\CipiApiClient;
use CipiGui\Services\CipiApiException;
use CipiGui\Support\Notifications as N;
use Livewire\Component;

/**
 * Notifications (API 1.42+ / Cipi ≥ 5.12.0): the server's Notifications tab.
 * Every channel — SMTP, Resend, SES, Slack, Discord, Mattermost, Google Chat,
 * Telegram, ntfy, Pushover, webhook — and every trigger. Every enabled
 * trigger reaches every enabled channel.
 *
 * Reading needs a level on the server; changing needs `manage`. Secrets are
 * never sent back by the API: in the form, an empty secret of an existing
 * channel keeps the stored one. ServerManage shows its older SMTP form when
 * the server is older than this (it checks before rendering this component).
 */
class Notifications extends Component
{
    use InteractsWithCipiServer;

    public bool $loaded = false;

    public bool $unsupported = false;

    public string $unsupportedReason = '';

    /** @var list<array<string, mixed>> */
    public array $channels = [];

    /** @var array{enabled?: int, total?: int} */
    public array $summary = [];

    /** @var list<array{id: string, category: string, label: string, enabled: bool}> */
    public array $triggers = [];

    /** trigger id => on, as the server has it */
    public array $triggersSaved = [];

    /** trigger id => on, as the form has it */
    public array $triggerState = [];

    public string $triggerSearch = '';

    /** channel id => {ok, message} of the last test from this page */
    public array $tests = [];

    // ── the channel form ──
    public bool $formOpen = false;

    /** null: a new channel */
    public ?string $editing = null;

    public string $formType = 'slack';

    public string $formId = '';

    /** @var array<string, string> */
    public array $form = [];

    public bool $formTls = true;

    public bool $formEnabled = true;

    public bool $formTest = true;

    public bool $formClearSecret = false;

    public function mount(?int $serverId = null): void
    {
        $this->serverId = $serverId ?? (int) session('cipi_gui_server_id');
    }

    public function init(): void
    {
        $this->load();
    }

    public function canManage(): bool
    {
        return Access::canManageServer($this->panelUser(), (int) ($this->serverId ?? 0));
    }

    protected function mayRead(): bool
    {
        $id = (int) ($this->serverId ?? 0);

        return $id !== 0 && Access::canViewServer($this->panelUser(), $id);
    }

    public function load(): void
    {
        if (! $this->mayRead()) {
            $this->loaded = true;

            return;
        }
        try {
            $api = $this->client();
            $api->prefetch(['/notifications', '/notifications/triggers']);
            $this->syncStatus($api->notifications());
            $this->syncTriggers($api->notificationTriggers());
            $this->unsupported = false;
            $this->unsupportedReason = '';
            $this->error = null;
        } catch (CipiApiException $e) {
            if ($this->isUnsupported($e) || $e->getStatusCode() === 503) {
                $this->unsupported = true;
                $this->unsupportedReason = $this->friendlyApiError($e);
            } else {
                $this->error = $this->friendlyApiError($e);
            }
        } finally {
            CipiApiClient::forgetPrefetched();
            $this->loaded = true;
        }
    }

    protected function syncStatus(array $status): void
    {
        $this->channels = array_values(array_filter((array) ($status['channels'] ?? []), 'is_array'));
        $this->summary = (array) ($status['triggers'] ?? []);
    }

    protected function syncTriggers(array $data): void
    {
        $this->triggers = array_values(array_filter((array) ($data['triggers'] ?? []), fn ($t) => is_array($t) && ! empty($t['id'])));
        $this->triggersSaved = [];
        foreach ($this->triggers as $t) {
            $this->triggersSaved[$t['id']] = (bool) ($t['enabled'] ?? true);
        }
        $this->triggerState = $this->triggersSaved;
        $this->summary = ['enabled' => (int) ($data['enabled'] ?? count(array_filter($this->triggersSaved))), 'total' => (int) ($data['total'] ?? count($this->triggersSaved))];
    }

    protected function guardManage(): bool
    {
        if (! $this->canManage()) {
            $this->dispatch('notify', type: 'error', message: 'Managing notifications needs Manage access to this server.');

            return false;
        }

        return true;
    }

    // ── channels ─────────────────────────────────────────────────────

    public function hasSmtp(): bool
    {
        foreach ($this->channels as $ch) {
            if (($ch['type'] ?? '') === 'smtp') {
                return true;
            }
        }

        return false;
    }

    public function openAdd(string $type = 'slack'): void
    {
        if (! $this->guardManage()) {
            return;
        }
        $this->resetValidation();
        $this->editing = null;
        $this->formOpen = true;
        $this->formId = '';
        $this->setFormType(array_key_exists($type, N::TYPES) ? $type : 'slack');
        $this->formEnabled = true;
        $this->formTest = true;
        $this->formClearSecret = false;
    }

    public function setFormType(string $type): void
    {
        if ($this->editing !== null || ! array_key_exists($type, N::TYPES)) {
            return;
        }
        $this->formType = $type;
        $this->form = array_fill_keys(array_keys(N::FIELDS[$type]), '');
        if ($type === 'smtp') {
            $this->form['port'] = '587';
        }
        if ($type === 'ntfy') {
            $this->form['priority'] = 'default';
        }
        $this->formTls = true;
        if ($this->formId === '' || array_key_exists($this->formId, N::TYPES)) {
            $this->formId = $type === 'smtp' ? '' : $this->suggestId($type);
        }
        $this->resetValidation();
    }

    protected function suggestId(string $type): string
    {
        $taken = array_column($this->channels, 'id');
        $base = $type;
        $id = $base;
        for ($i = 2; in_array($id, $taken, true); $i++) {
            $id = $base.'-'.$i;
        }

        return $id;
    }

    public function openEdit(string $id): void
    {
        if (! $this->guardManage()) {
            return;
        }
        $ch = $this->channel($id);
        if ($ch === null) {
            return;
        }
        $this->resetValidation();
        $this->editing = $id;
        $this->formOpen = true;
        $this->formType = (string) $ch['type'];
        $this->formId = $id;
        $this->form = N::formValues($ch);
        $this->formTls = ! array_key_exists('tls', $ch) || (bool) $ch['tls'];
        $this->formEnabled = (bool) ($ch['enabled'] ?? true);
        $this->formTest = false;
        $this->formClearSecret = false;
    }

    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->editing = null;
        $this->resetValidation();
    }

    /** @return array<string, mixed>|null */
    public function channel(string $id): ?array
    {
        foreach ($this->channels as $ch) {
            if (($ch['id'] ?? null) === $id) {
                return $ch;
            }
        }

        return null;
    }

    public function saveChannel(): void
    {
        if (! $this->guardManage()) {
            return;
        }
        $type = $this->formType;
        $fields = N::FIELDS[$type] ?? null;
        if ($fields === null) {
            return;
        }
        $adding = $this->editing === null;

        $rules = [];
        $messages = [];
        if ($adding && $type !== 'smtp') {
            $rules['formId'] = ['required', 'regex:/^[a-z0-9][a-z0-9-]{0,31}$/', function ($attr, $value, $fail) {
                if ($value === 'smtp' || $this->channel((string) $value) !== null) {
                    $fail('This id is taken.');
                }
            }];
            $messages['formId.regex'] = 'Lowercase letters, digits and dashes, up to 32.';
        }
        foreach ($fields as $field => [$label, $input, , $required]) {
            $keep = ! $adding && N::isSecret($field);
            $r = [($required && ! $keep) ? 'required' : 'nullable', 'string', 'max:1000'];
            if ($field === 'url') {
                $r[] = in_array($type, ['ntfy', 'webhook'], true) ? 'regex:/^https?:\/\/\S+$/' : 'regex:/^https:\/\/\S+$/';
                $messages["form.url.regex"] = in_array($type, ['ntfy', 'webhook'], true) ? 'An http(s) URL, without spaces.' : 'An https:// URL, without spaces.';
            }
            if ($field === 'port') {
                $r = ['nullable', 'integer', 'min:1', 'max:65535'];
            }
            if ($field === 'chat_id') {
                $r[] = 'regex:/^-?[0-9]+$/';
                $messages['form.chat_id.regex'] = 'A number (negative for groups).';
            }
            if ($field === 'from' || $field === 'to') {
                $r[] = 'regex:/@/';
                $messages["form.{$field}.regex"] = $field === 'to' ? 'One or more email addresses, comma-separated.' : 'An email address.';
            }
            $rules["form.{$field}"] = $r;
            $messages["form.{$field}.required"] = $label.' is required.';
        }
        $this->validate($rules, $messages);

        $payload = [];
        foreach (array_keys($fields) as $field) {
            $v = trim((string) ($this->form[$field] ?? ''));
            if ($v === '') {
                continue; // a secret left empty is kept; an optional field left empty is not sent
            }
            if (! $adding && ! N::isSecret($field) && $v === trim((string) (N::formValues((array) $this->channel($this->editing))[$field] ?? ''))) {
                continue; // unchanged
            }
            $payload[$field] = $field === 'port' ? (int) $v : $v;
        }
        if ($type === 'smtp' && ($adding || $this->formTls !== (bool) ($this->channel('smtp')['tls'] ?? true))) {
            $payload['tls'] = $this->formTls;
        }
        if ($type === 'webhook' && ! $adding && $this->formClearSecret) {
            unset($payload['secret']);
            $payload['clear_secret'] = true;
        }
        if ($adding || $this->formEnabled !== (bool) ($this->channel($this->editing)['enabled'] ?? true)) {
            $payload['enabled'] = $this->formEnabled;
        }
        if ($this->formTest) {
            $payload['test'] = true;
        }
        if (! $adding && $payload === []) {
            $this->closeForm();

            return;
        }

        try {
            $api = $this->client();
            if ($adding) {
                $payload = ['type' => $type] + ($type === 'smtp' ? [] : ['id' => $this->formId]) + $payload;
                $out = $api->addNotificationChannel($payload);
            } else {
                $out = $api->updateNotificationChannel($this->editing, $payload);
            }
            $id = $adding ? ($type === 'smtp' ? 'smtp' : $this->formId) : $this->editing;
            if (is_array($out['test'] ?? null)) {
                $this->tests[$id] = $out['test'];
            }
            $this->syncStatus($api->notifications());
            $this->closeForm();
            $test = $out['test'] ?? null;
            if (is_array($test) && empty($test['ok'])) {
                $this->dispatch('notify', type: 'error', message: "'{$id}' saved, but the test failed: ".($test['message'] ?? ''));
            } else {
                $this->dispatch('notify', type: 'success', message: ($adding ? "Channel '{$id}' added" : "Channel '{$id}' saved").(is_array($test) ? ' — test sent.' : '.'));
            }
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function toggleChannel(string $id): void
    {
        $ch = $this->channel($id);
        if ($ch === null || ! $this->guardManage()) {
            return;
        }
        $on = empty($ch['enabled']);
        try {
            $api = $this->client();
            $api->setNotificationChannelEnabled($id, $on);
            $this->syncStatus($api->notifications());
            $this->dispatch('notify', type: 'success', message: $on ? "'{$id}' is on." : "'{$id}' paused — nothing is sent to it.");
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function testChannel(string $id): void
    {
        if (! $this->guardManage() || ($id !== 'all' && $this->channel($id) === null)) {
            return;
        }
        try {
            $r = $this->client()->testNotificationChannel($id);
            $this->tests[$id] = $r;
            $this->dispatch('notify', type: ! empty($r['ok']) ? 'success' : 'error', message: (string) ($r['message'] ?? ''));
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function removeChannel(string $id): void
    {
        if (! $this->guardManage() || $this->channel($id) === null) {
            return;
        }
        try {
            $this->syncStatus($this->client()->removeNotificationChannel($id));
            unset($this->tests[$id]);
            if ($this->editing === $id) {
                $this->closeForm();
            }
            $this->dispatch('notify', type: 'success', message: "Channel '{$id}' removed.");
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    // ── triggers ─────────────────────────────────────────────────────

    /** Trigger ids whose state differs from the server's. */
    public function changedTriggers(): array
    {
        $out = [];
        foreach ($this->triggerState as $id => $on) {
            if (array_key_exists($id, $this->triggersSaved) && (bool) $on !== $this->triggersSaved[$id]) {
                $out[$id] = (bool) $on;
            }
        }

        return $out;
    }

    /** category => triggers, in catalog order, filtered by the search. */
    public function triggerGroups(): array
    {
        $q = mb_strtolower(trim($this->triggerSearch));
        $groups = [];
        foreach ($this->triggers as $t) {
            if ($q !== '' && ! str_contains(mb_strtolower($t['id'].' '.($t['label'] ?? '').' '.($t['category'] ?? '')), $q)) {
                continue;
            }
            $groups[$t['category'] ?? 'Other'][] = $t;
        }

        return $groups;
    }

    public function setCategory(string $category, bool $on): void
    {
        foreach ($this->triggers as $t) {
            if (($t['category'] ?? '') === $category) {
                $this->triggerState[$t['id']] = $on;
            }
        }
    }

    public function setAllTriggers(bool $on): void
    {
        foreach (array_keys($this->triggerState) as $id) {
            $this->triggerState[$id] = $on;
        }
    }

    public function discardTriggers(): void
    {
        $this->triggerState = $this->triggersSaved;
    }

    public function saveTriggers(): void
    {
        if (! $this->guardManage()) {
            return;
        }
        $changed = $this->changedTriggers();
        if ($changed === []) {
            return;
        }
        try {
            $this->syncTriggers($this->client()->updateNotificationTriggers(['triggers' => $changed]));
            $this->dispatch('notify', type: 'success', message: count($changed).' trigger'.(count($changed) === 1 ? '' : 's').' saved.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function resetTriggers(): void
    {
        if (! $this->guardManage()) {
            return;
        }
        try {
            $this->syncTriggers($this->client()->resetNotificationTriggers());
            $this->dispatch('notify', type: 'success', message: 'Every trigger is on again.');
        } catch (CipiApiException $e) {
            $this->handleApiError($e);
        }
    }

    public function render()
    {
        return view('cipi-gui::livewire.notifications', [
            'canManage' => $this->canManage(),
            'groups' => $this->triggerGroups(),
            'changed' => $this->changedTriggers(),
        ]);
    }
}
