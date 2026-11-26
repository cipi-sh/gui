<?php

namespace CipiGui\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use CipiGui\Support\Panel;
use CipiGui\Support\Theme;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    /** Set at the SSO callback: the identity provider ran its own MFA (SsoController). */
    public const SSO_SESSION = 'cipi_gui_sso';

    /** The secret being enrolled on the setup page, until the first code confirms it. */
    public const SETUP_SECRET = 'cipi_gui_2fa_setup_secret';

    public function __construct(
        protected Google2FA $google2fa = new Google2FA,
    ) {}

    /**
     * The account must set up 2FA before anything else (4.6.0): an admin
     * requires it for everyone (Team), the account has none, and this session
     * did not come through SSO — the identity provider runs its own MFA, and
     * the panel never asks for its code after SSO.
     */
    public static function setupRequired(?object $user, ?Session $session): bool
    {
        return Panel::enforceTwoFactor() && self::setupRequiredIfEnforced($user, $session);
    }

    /**
     * A second factor: the authenticator app's code, or a passkey (4.8.0) — it
     * is the device and the face, finger or PIN that unlocks it.
     */
    public static function hasSecondFactor(?object $user): bool
    {
        return $user !== null && ((bool) ($user->two_factor_enabled ?? false) || Passkeys::has($user));
    }

    /** The same question as if 2FA were required: no second factor on the account, and not an SSO session. */
    public static function setupRequiredIfEnforced(?object $user, ?Session $session): bool
    {
        if ($user === null || self::hasSecondFactor($user)) {
            return false;
        }

        return ! ($session !== null && $session->get(self::SSO_SESSION));
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function getQrCodeUrl(Authenticatable $user, string $secret): string
    {
        // The name an authenticator files the code under. Not APP_NAME: a
        // fresh install has "Laravel" there. An APP_NAME somebody set (a
        // company's name for its panel) is kept, so a re-enrolment lands in
        // the same entry.
        $appName = trim((string) config('app.name', ''));
        $company = $appName === '' || $appName === 'Laravel' ? Theme::NAME : $appName;

        return $this->google2fa->getQRCodeUrl(
            $company,
            $user->email,
            $secret,
        );
    }

    /** The QR code of the otpauth:// URL, as inline SVG (Settings and the setup page). */
    public function qrCodeSvg(Authenticatable $user, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd));

        return $writer->writeString($this->getQrCodeUrl($user, $secret));
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

    /** Also moves 2FA to a new device: the secret is replaced once a code of the new one checks out. */
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
