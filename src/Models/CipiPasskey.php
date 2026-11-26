<?php

namespace CipiGui\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A passkey of an account (Settings → Passkeys): the public key the panel
 * checks a sign-in against, the name the person gave it, when it was used.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $credential_hash sha256 of the raw id, hex
 * @property string $credential_id base64url
 * @property string $public_key PEM
 * @property int $alg COSE algorithm (-7 ES256, -257 RS256)
 * @property int $sign_count
 * @property string|null $aaguid
 * @property list<string>|null $transports
 * @property bool $backed_up synced (iCloud Keychain, Google Password Manager, 1Password…)
 * @property \Illuminate\Support\Carbon|null $last_used_at
 */
class CipiPasskey extends Model
{
    protected $table = 'cipi_passkeys';

    protected $fillable = ['user_id', 'name', 'credential_hash', 'credential_id', 'public_key', 'alg', 'sign_count', 'aaguid', 'transports', 'backed_up', 'last_used_at'];

    protected $hidden = ['public_key'];

    /** The lookup key of a credential id (base64url, as the browser sends it). */
    public static function hashOf(string $credentialId): string
    {
        return hash('sha256', $credentialId);
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'alg' => 'integer',
            'sign_count' => 'integer',
            'transports' => 'array',
            'backed_up' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }
}
