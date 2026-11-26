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
 * Telegram, ntfy, Pushover, webhook — and every trigger. An enabled trigger
 * reaches every enabled channel that takes it: everything, or only the
 * categories (and "urgent": failures and security events) it was given —
 * when the server keeps them (it lists its `categories`).
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

    /** The server's categories of triggers, [{id, label, triggers}]; empty: it does not keep events per channel. */
    public array $categories = [];

    /** all: the channel takes everything; some: only $formEvents (and $formEventsExtra). */
    public string $formEventsMode = 'all';

    /** selector => chosen: "urgent" and the category ids */
    public array $formEvents = [];

    /** Single triggers the channel takes (set at the server): kept as they are. */
    public array $formEventsExtra = [];

    /**
     * How notifications read (Cipi 5.17.0): {timezone, server_timezone,
     * server_name, hostname, now}; null on a server that does not say.
     */
    public ?array $settings = null;

    /** The form: an IANA name, or '' for the server's own time zone. */
    public string $settingsTimezone = '';

    /** The form: the name notifications give the server, or '' for its hostname. */
    public string $settingsServerName = '';

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
            $status = $api->notifications();
            $this->syncStatus($status);
            $this->syncSettings($status['settings'] ?? null);
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
        $this->categories = array_values(array_filter((array) ($status['categories'] ?? []),
            fn ($c) => is_array($c) && is_string($c['id'] ?? null) && preg_match('/^[a-z][a-z0-9-]{0,40}$/', $c['id']) === 1));
    }

    /** @param  mixed  $settings  `settings` of the status, or the answer of a change */
    protected function syncSettings(mixed $settings): void
    {
        if (! is_array($settings) || ! is_string($settings['server_timezone'] ?? null)) {
            $this->settings = null;

            return;
        }
        $text = fn (string $key): ?string => is_string($settings[$key] ?? null) && $settings[$key] !== '' ? $settings[$key] : null;
        $this->settings = [
            'timezone' => $text('timezone'),
            'server_timezone' => $settings['server_timezone'],
            'server_name' => $text('server_name'),
            'hostname' => $text('hostname') ?? '',
            'now' => $text('now') ?? '',
        ];
        $this->settingsTimezone = $this->settings['timezone'] ?? '';
        $this->settingsServerName = $this->settings['server_name'] ?? '';
    }

    /** The name this panel gives the server, when it is one a notification can carry and is not in use already. */
    public function panelServerName(): ?string
    {
        $name = trim((string) $this->currentServer()?->name);
        if ($name === '' || preg_match(N::SERVER_NAME_PATTERN, $name) !== 1 || $this->settings === null) {
            return null;
        }

        return $name !== ($this->settings['server_name'] ?? null) && strcasecmp($name, (string) $this->settings['hostname']) !== 0 ? $name : null;
    }

    /** Time zones for the form: the server's own first (''), then every IANA name PHP knows. */
    public function timezoneChoices(): array
    {
        return \DateTimeZone::listIdentifiers();
    }

    public function saveSettings(): void
    {
        if (! $this->guardManage() || $this->settings === null) {
            return;
        }
        $this->resetErrorBag(['settingsTimezone', 'settingsServerName']);
        $timezone = trim($this->settingsTimezone);
        $name = trim($this->settingsServerName);
        if ($timezone !== '' && (preg_match(N::TIMEZONE_PATTERN, $timezone) !== 1 || ! in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true))) {
            $this->addError('settingsTimezone', 'Choose a time zone such as Europe/Rome, or leave it to the server\'s own.');

            return;
        }
        if ($name !== '' && preg_match(N::SERVER_NAME_PATTERN, $name) !== 1) {
            $this->addError('settingsServerName', 'Letters, digits, spaces, dots, dashes and underscores — 40 characters at most.');

            return;
        }

        $payload = [];
        if ($timezone !== ($this->settings['timezone'] ?? '')) {
            $payload['timezone'] = $timezone === '' ? null : $timezone;
        }
        if ($name !== ($this->settings['server_name'] ?? '')) {
            $payload['server_name'] = $name === '' ? null : $name;
        }
        if ($payload === []) {
            $this->dispatch('notify', type: 'info', message: 'Nothing to change.');

            return;
        }

        try {
            $this->syncSettings($this->client()->updateNotificationSettings($payload));
            $this->dispatch('notify', type: 'success', message: 'Saved — the next notification is written this way.');
        } catch (CipiApiException $e) {
            if ($e->getStatusCode() === 422) {
                $this->addError(array_key_exists('timezone', $payload) && ! array_key_exists('server_name', $payload) ? 'settingsTimezone' : 'settingsServerName', self::plainCliText($e->getMessage()));

                return;
            }
            $this->handleApiError($e);
        }
    }

    /** One click: notifications call the server what this panel calls it. */
    public function usePanelName(): void
    {
        $name = $this->panelServerName();
        if ($name === null) {
            return;
        }
        $this->settingsServerName = $name;
        $this->saveSettings();
    }

    /** Whether the server keeps what each channel takes. */
    public function supportsEvents(): bool
    {
        return $this->categories !== [];
    }

    /** The form's choice from a channel's events (or everything, for a new one). */
    protected function fillEventsForm(?array $events): void
    {
        $known = array_merge([N::URGENT], array_column($this->categories, 'id'));
        $this->formEventsMode = $events === null ? 'all' : 'some';
        $this->formEvents = array_fill_keys($known, false);
        $this->formEventsExtra = [];
        foreach ($events ?? [] as $e) {
            if (in_array($e, $known, true)) {
                $this->formEvents[$e] = true;
            } else {
                $this->formEventsExtra[] = $e;
            }
        }
    }

    /** @return list<string>|null what the form says the channel takes */
    protected function formEventsValue(): ?array
    {
        if ($this->formEventsMode !== 'some') {
            return null;
        }
        $known = array_merge([N::URGENT], array_column($this->categories, 'id'));
        $picked = array_values(array_filter($known, fn ($e) => ! empty($this->formEvents[$e])));

        return array_values(array_unique(array_merge($picked, array_filter($this->formEventsExtra, 'is_string'))));
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
        $this->fillEventsForm(null);
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
        $this->fillEventsForm(N::events($ch));
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
        $events = $this->supportsEvents() ? $this->formEventsValue() : null;
        if ($this->supportsEvents() && $events === []) {
            $this->addError('formEvents', 'Choose at least one kind of event — or Everything.');

            return;
        }

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
        $sorted = function (?array $e): ?array {
            if ($e !== null) {
                sort($e);
            }

            return $e;
        };
        if ($this->supportsEvents() && ($adding ? $events !== null : $sorted($events) !== $sorted(N::events((array) $this->channel($this->editing))))) {
            $payload['events'] = $events;
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
            'panelName' => $this->panelServerName(),
        ]);
    }
}
