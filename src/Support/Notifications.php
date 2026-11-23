<?php

namespace CipiGui\Support;

/**
 * Channel types of `cipi notification` (Cipi ≥ 5.12.0 / API 1.42+): what
 * each one is, the fields its form shows and which of them are secrets — a
 * secret is never sent back by the API, so on an existing channel an empty
 * secret field means "keep the stored one".
 */
final class Notifications
{
    /** type => [label, short description, icon] */
    public const TYPES = [
        'smtp' => ['Email (SMTP)', 'Any SMTP server, port 587. One per server.', 'mail'],
        'resend' => ['Resend', 'Email through the Resend API.', 'mail'],
        'ses' => ['Amazon SES', 'Email through SES, signed by Cipi.', 'mail'],
        'slack' => ['Slack', 'Incoming webhook of a channel.', 'bolt'],
        'discord' => ['Discord', 'Webhook of a channel.', 'bolt'],
        'mattermost' => ['Mattermost', 'Incoming webhook.', 'bolt'],
        'googlechat' => ['Google Chat', 'Incoming webhook of a space.', 'bolt'],
        'telegram' => ['Telegram', 'A bot of yours, to a chat or group.', 'signal'],
        'ntfy' => ['ntfy', 'Push to a phone, urgent events raised.', 'signal'],
        'pushover' => ['Pushover', 'Push to a phone.', 'signal'],
        'webhook' => ['Webhook', 'JSON POST, optionally signed.', 'link'],
    ];

    /**
     * type => field => [label, input type, placeholder, required to add, hint].
     * Field names are the API's.
     */
    public const FIELDS = [
        'smtp' => [
            'host' => ['SMTP host', 'text', 'smtp.example.com', true, null],
            'port' => ['Port', 'number', '587', false, null],
            'user' => ['Username', 'text', '', true, null],
            'password' => ['Password', 'password', '', true, null],
            'from' => ['From', 'text', 'cipi@example.com', true, null],
            'to' => ['Send to', 'text', 'ops@example.com', true, 'One or more addresses, comma-separated.'],
        ],
        'resend' => [
            'api_key' => ['API key', 'password', 're_…', true, null],
            'from' => ['From', 'text', 'Cipi <alerts@example.com>', true, 'On a domain verified in Resend.'],
            'to' => ['Send to', 'text', 'ops@example.com', true, 'One or more addresses, comma-separated.'],
        ],
        'ses' => [
            'region' => ['Region', 'text', 'eu-west-1', true, null],
            'access_key' => ['Access key id', 'text', 'AKIA…', true, 'Of an IAM user allowed ses:SendEmail.'],
            'secret_key' => ['Secret access key', 'password', '', true, null],
            'from' => ['From', 'text', 'alerts@example.com', true, 'An identity verified in SES.'],
            'to' => ['Send to', 'text', 'ops@example.com', true, 'Comma-separated; verified too while SES is in its sandbox.'],
        ],
        'slack' => ['url' => ['Webhook URL', 'password', 'https://hooks.slack.com/services/…', true, 'Slack → Apps → Incoming Webhooks.']],
        'discord' => ['url' => ['Webhook URL', 'password', 'https://discord.com/api/webhooks/…', true, 'Channel settings → Integrations → Webhooks.']],
        'mattermost' => ['url' => ['Webhook URL', 'password', 'https://chat.example.com/hooks/…', true, null]],
        'googlechat' => ['url' => ['Webhook URL', 'password', 'https://chat.googleapis.com/v1/spaces/…', true, null]],
        'telegram' => [
            'token' => ['Bot token', 'password', '123456:ABC-DEF…', true, 'From @BotFather.'],
            'chat_id' => ['Chat id', 'text', '-1001234567890', true, 'Send /start to the bot first; group ids are negative.'],
        ],
        'ntfy' => [
            'url' => ['Topic URL', 'password', 'https://ntfy.sh/your-topic', true, null],
            'priority' => ['Priority', 'select', 'default', false, 'Urgent events (failed deploys, SSH logins…) are raised to high anyway.'],
        ],
        'pushover' => [
            'token' => ['Application token', 'password', '', true, null],
            'user' => ['User or group key', 'text', '', true, null],
        ],
        'webhook' => [
            'url' => ['URL', 'password', 'https://example.com/hooks/cipi', true, 'http:// is allowed for a receiver on a private network.'],
            'secret' => ['Signing secret', 'password', '', false, 'Optional: adds X-Cipi-Signature: sha256=HMAC of the body.'],
        ],
    ];

    /** Fields the API never sends back, and the *_set flag that says one is stored. */
    public const SECRETS = ['password' => null, 'api_key' => 'api_key_set', 'secret_key' => 'secret_key_set', 'token' => 'token_set', 'secret' => 'secret_set', 'url' => null];

    public const PRIORITIES = ['default', 'min', 'low', 'high', 'urgent'];

    public static function label(string $type): string
    {
        return self::TYPES[$type][0] ?? ucfirst($type);
    }

    public static function icon(string $type): string
    {
        return self::TYPES[$type][2] ?? 'bolt';
    }

    public static function isSecret(string $field): bool
    {
        return array_key_exists($field, self::SECRETS);
    }

    /** Where a channel sends, in one line, with nothing secret in it. */
    public static function target(array $ch): string
    {
        $to = $ch['to'] ?? '';
        $to = is_array($to) ? implode(', ', $to) : (string) $to;

        return match ($ch['type'] ?? '') {
            'smtp' => trim(($ch['host'] ?? '').(! empty($ch['port']) ? ':'.$ch['port'] : '').' · '.($ch['from'] ?? '').' → '.$to, ' ·'),
            'resend' => ($ch['from'] ?? '').' → '.$to,
            'ses' => ($ch['region'] ?? '').' · '.($ch['from'] ?? '').' → '.$to,
            'telegram' => 'chat '.($ch['chat_id'] ?? '?'),
            'pushover' => 'user '.mb_substr((string) ($ch['user'] ?? ''), 0, 6).'…',
            'webhook' => (string) ($ch['url'] ?? '').(! empty($ch['secret_set']) ? ' · signed' : ''),
            'ntfy' => (string) ($ch['url'] ?? '').(! empty($ch['priority']) && $ch['priority'] !== 'default' ? ' · '.$ch['priority'] : ''),
            default => (string) ($ch['url'] ?? ''),
        };
    }

    /** The form's values for an existing channel: what the API returns, secrets left empty. */
    public static function formValues(array $ch): array
    {
        $type = (string) ($ch['type'] ?? '');
        $values = [];
        foreach (array_keys(self::FIELDS[$type] ?? []) as $field) {
            if (self::isSecret($field)) {
                $values[$field] = '';

                continue;
            }
            $v = $ch[$field] ?? '';
            $values[$field] = is_array($v) ? implode(', ', $v) : (string) $v;
        }
        if ($type === 'ntfy' && ($values['priority'] ?? '') === '') {
            $values['priority'] = 'default';
        }

        return $values;
    }

    /** Whether a secret field holds something on the server (shown as "stored — leave empty to keep"). */
    public static function secretStored(array $ch, string $field): bool
    {
        if ($field === 'url' || $field === 'password') {
            return true; // a URL channel or the SMTP channel cannot exist without them
        }
        $flag = self::SECRETS[$field] ?? null;

        return $flag !== null && ! empty($ch[$flag]);
    }
}
