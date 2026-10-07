<?php

namespace CipiGui\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    public function __construct(
        protected Google2FA $google2fa = new Google2FA,
    ) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function getQrCodeUrl(Authenticatable $user, string $secret): string
    {
        $company = config('app.name', 'Cipi GUI');

        return $this->google2fa->getQRCodeUrl(
            $company,
            $user->email,
            $secret,
        );
    }

    public function verify(Authenticatable $user, string $code): bool
    {
        if (! $user->two_factor_secret) {
            return false;
        }

        try {
            $secret = Crypt::decryptString($user->two_factor_secret);
        } catch (\Throwable) {
            return false;
        }

        return (bool) $this->google2fa->verifyKey($secret, $code);
    }

    public function enable(Authenticatable $user, string $secret, string $code): bool
    {
        $secretToVerify = $secret;

        if (! $this->google2fa->verifyKey($secretToVerify, $code)) {
            return false;
        }

        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return true;
    }

    public function disable(Authenticatable $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
        ])->save();
    }
}
