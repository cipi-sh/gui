<?php

namespace CipiGui\Tests\Feature;

use CipiGui\Services\Access;
use CipiGui\Services\OidcClient;
use CipiGui\Tests\Fixtures\User;
use CipiGui\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

class SsoTest extends TestCase
{
    private const ISSUER = 'https://login.microsoftonline.com/11111111-2222-3333-4444-555555555555/v2.0';

    // What the fake provider answers; fakeProvider() changes them between sign-ins of one test.
    private array $userinfo = [];

    private array $idToken = [];

    private mixed $tokenResponse = null;

    private bool $providerFaked = false;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config()->set('cipi-gui.sso.issuer', self::ISSUER);
        config()->set('cipi-gui.sso.client_id', 'panel-client');
        config()->set('cipi-gui.sso.client_secret', 'panel-secret');
        config()->set('cipi-gui.sso.label', '');
        config()->set('cipi-gui.sso.domains', '');
        config()->set('cipi-gui.sso.only', false);
        config()->set('cipi-gui.sso.scopes', 'openid email profile');
    }

    // ── login page ───────────────────────────────────────────────────

    #[Test]
    public function the_login_page_offers_the_button_only_when_sso_is_configured(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Sign in with Microsoft Entra ID')
            ->assertSeeHtml('href="'.route('cipi-gui.sso.redirect').'"')
            ->assertSee('Keep me signed in');

        config()->set('cipi-gui.sso.label', 'Acme SSO');
        $this->get('/login')->assertSee('Sign in with Acme SSO');

        config()->set('cipi-gui.sso.issuer', '');
        $this->get('/login')->assertOk()->assertDontSee('Sign in with')->assertSee('Keep me signed in');
        $this->get('/auth/sso')->assertNotFound();
        $this->get('/auth/sso/callback?code=x&state=y')->assertNotFound();
    }

    #[Test]
    public function the_label_follows_the_issuer_host_unless_set(): void
    {
        $cases = [
            self::ISSUER => 'Microsoft Entra ID',
            'https://accounts.google.com' => 'Google',
            'https://dev-123.okta.com/oauth2/default' => 'Okta',
            'https://sso.example.com/realms/acme' => 'SSO',
        ];
        foreach ($cases as $issuer => $label) {
            config()->set('cipi-gui.sso.issuer', $issuer);
            $this->assertSame($label, OidcClient::label(), $issuer);
        }

        config()->set('cipi-gui.sso.label', 'Acme');
        $this->assertSame('Acme', OidcClient::label());
    }

    #[Test]
    public function sso_only_hides_the_password_form_and_refuses_password_sign_in(): void
    {
        config()->set('cipi-gui.sso.only', true);
        $this->admin();

        $this->get('/login')->assertOk()
            ->assertSee('Sign in with Microsoft Entra ID')
            ->assertSee('Password sign-in is off on this panel.')
            ->assertDontSee('Keep me signed in')
            ->assertDontSeeHtml('name="password"');

        $this->from('/login')->post('/login', ['email' => 'admin@cipi.local', 'password' => 'secret-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // ── redirect ─────────────────────────────────────────────────────

    #[Test]
    public function the_redirect_sends_to_the_provider_with_pkce_and_keeps_the_handshake_in_the_session(): void
    {
        $this->fakeProvider();

        $response = $this->get('/auth/sso');
        $response->assertRedirect()
            ->assertSessionHas('cipi_gui_sso_state')
            ->assertSessionHas('cipi_gui_sso_nonce')
            ->assertSessionHas('cipi_gui_sso_verifier');

        $url = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://idp.example.com/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('panel-client', $query['client_id']);
        $this->assertSame(route('cipi-gui.sso.callback'), $query['redirect_uri']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame(session('cipi_gui_sso_state'), $query['state']);
        $this->assertSame(session('cipi_gui_sso_nonce'), $query['nonce']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $verifier = session('cipi_gui_sso_verifier');
        $this->assertGreaterThanOrEqual(43, strlen($verifier));
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), $query['code_challenge']);

        // Discovery is cached: a second start does not fetch it again.
        $this->get('/auth/sso')->assertRedirect();
        Http::assertSentCount(1);
    }

    #[Test]
    public function an_unreachable_provider_is_reported_on_the_login_page(): void
    {
        Http::fake([self::ISSUER.'/.well-known/openid-configuration' => Http::response('', 503)]);

        $this->get('/auth/sso')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // ── callback ─────────────────────────────────────────────────────

    #[Test]
    public function the_callback_signs_in_an_existing_account_by_email_and_links_it(): void
    {
        $this->fakeProvider();
        $user = $this->member(['email' => 'Mario@cipi.local']);

        $this->withSession($this->handshake())
            ->get('/auth/sso/callback?code=abc&state=state-1')
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('sub-mario', $user->fresh()->sso_subject);
        $this->assertTrue(session('cipi_gui_2fa_verified'));
        $this->assertNull(session('cipi_gui_sso_state'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://idp.example.com/token'
            && $request->isForm()
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'abc'
            && $request['code_verifier'] === 'verifier-1'
            && $request['client_id'] === 'panel-client'
            && $request['client_secret'] === 'panel-secret'
            && $request['redirect_uri'] === route('cipi-gui.sso.callback'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://idp.example.com/userinfo'
            && $request->hasHeader('Authorization', 'Bearer at-1'));
    }

    #[Test]
    public function an_account_already_linked_is_found_by_subject_even_if_its_email_changed(): void
    {
        $this->fakeProvider();
        $user = $this->member(['email' => 'old@cipi.local', 'sso_subject' => 'sub-mario']);

        $this->withSession($this->handshake())->get('/auth/sso/callback?code=abc&state=state-1')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_user_with_2fa_is_not_asked_for_the_code_after_sso(): void
    {
        $this->fakeProvider();
        $user = $this->member(['two_factor_enabled' => true, 'two_factor_secret' => 'encrypted']);

        $this->withSession($this->handshake())->get('/auth/sso/callback?code=abc&state=state-1')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertOk()->assertSee('Dashboard');
        $this->get('/2fa')->assertRedirect('/');
    }

    #[Test]
    public function the_intended_page_is_opened_after_sso(): void
    {
        $this->fakeProvider();
        $this->member();

        $this->get('/settings')->assertRedirect('/login');
        $this->withSession($this->handshake())->get('/auth/sso/callback?code=abc&state=state-1')->assertRedirect('/settings');
    }

    #[Test]
    public function a_state_that_does_not_match_is_refused(): void
    {
        $this->fakeProvider();
        $user = $this->member();

        $this->withSession($this->handshake())
            ->get('/auth/sso/callback?code=abc&state=someone-else')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertNull($user->fresh()->sso_subject);
        Http::assertNotSent(fn (Request $request) => $request->url() === 'https://idp.example.com/token');

        // No handshake in the session at all (a stale link) is refused too.
        $this->get('/auth/sso/callback?code=abc&state=state-1')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function an_id_token_with_another_nonce_audience_or_issuer_is_refused(): void
    {
        $user = $this->member();

        foreach ([['nonce' => 'other-nonce'], ['aud' => 'another-app'], ['iss' => 'https://evil.example.com']] as $bad) {
            $this->fakeProvider(idToken: $bad);

            $this->withSession($this->handshake())
                ->get('/auth/sso/callback?code=abc&state=state-1')
                ->assertRedirect('/login')
                ->assertSessionHasErrors('email');

            $this->assertGuest();
            $this->assertNull($user->fresh()->sso_subject);
            Http::assertNotSent(fn (Request $request) => $request->url() === 'https://idp.example.com/userinfo');
        }
    }

    #[Test]
    public function an_audience_given_as_a_list_is_accepted(): void
    {
        $this->fakeProvider(idToken: ['aud' => ['another-app', 'panel-client']]);
        $user = $this->member();

        $this->withSession($this->handshake())->get('/auth/sso/callback?code=abc&state=state-1')->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function an_unknown_email_is_refused_and_nothing_is_created(): void
    {
        $this->fakeProvider(['sub' => 'sub-nobody', 'email' => 'nobody@acme.com']);
        $this->admin();

        $this->withSession($this->handshake())
            ->get('/auth/sso/callback?code=abc&state=state-1')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'No panel account for nobody@acme.com. Ask an admin for an invite link.']);

        $this->assertGuest();
        $this->assertSame(1, User::count());
    }

    #[Test]
    public function an_email_from_an_allowed_domain_gets_a_user_account_with_no_access(): void
    {
        config()->set('cipi-gui.sso.domains', 'acme.com, example.org');
        $this->server();
        $this->fakeProvider(['sub' => 'sub-anna', 'email' => 'Anna@Acme.com', 'name' => 'Anna Bianchi']);

        $this->withSession($this->handshake())->get('/auth/sso/callback?code=abc&state=state-1')->assertRedirect('/');

        $anna = User::query()->where('email', 'anna@acme.com')->firstOrFail();
        $this->assertAuthenticatedAs($anna);
        $this->assertSame('Anna Bianchi', $anna->name);
        $this->assertSame('user', $anna->role);
        $this->assertSame('sub-anna', $anna->sso_subject);
        $this->assertFalse(Access::isAdmin($anna));
        $this->assertSame(['servers' => [], 'apps' => []], Access::grantsFor($anna));
        $this->assertSame('No access yet', Access::summary($anna));

        // Without a name claim the local part of the email names the account.
        auth()->logout();
        $this->fakeProvider(['sub' => 'sub-luca', 'email' => 'luca@example.org', 'name' => null]);
        $this->withSession($this->handshake())->get('/auth/sso/callback?code=abc&state=state-1')->assertRedirect('/');
        $this->assertSame('luca', User::query()->where('email', 'luca@example.org')->firstOrFail()->name);
    }

    #[Test]
    public function an_account_linked_to_another_identity_is_refused(): void
    {
        $this->fakeProvider();
        $user = $this->member(['sso_subject' => 'sub-someone-else']);

        $this->withSession($this->handshake())
            ->get('/auth/sso/callback?code=abc&state=state-1')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'The account mario@cipi.local is linked to another identity.']);

        $this->assertGuest();
        $this->assertSame('sub-someone-else', $user->fresh()->sso_subject);
    }

    #[Test]
    public function an_unverified_or_missing_email_is_refused(): void
    {
        $this->member();

        $this->fakeProvider(['email_verified' => false]);
        $this->withSession($this->handshake())->get('/auth/sso/callback?code=abc&state=state-1')
            ->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();

        // preferred_username is not an email.
        $this->fakeProvider(['email' => null, 'preferred_username' => 'mario@cipi.local']);
        $this->withSession($this->handshake())->get('/auth/sso/callback?code=abc&state=state-1')
            ->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function an_error_from_the_provider_lands_on_the_login_page(): void
    {
        $this->fakeProvider();

        $this->withSession($this->handshake())
            ->get('/auth/sso/callback?error=access_denied&error_description=User+declined&state=state-1')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'Microsoft Entra ID refused the sign-in: User declined']);

        $this->assertGuest();
        Http::assertNothingSent();

        $this->get('/login')->assertOk()->assertSee('Microsoft Entra ID refused the sign-in: User declined');
    }

    #[Test]
    public function a_token_endpoint_error_is_refused_with_its_reason(): void
    {
        $this->fakeProvider(tokenResponse: Http::response(['error' => 'invalid_grant', 'error_description' => 'Code expired'], 400));
        $this->member();

        $this->withSession($this->handshake())->get('/auth/sso/callback?code=abc&state=state-1')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'The identity provider did not issue a token: Code expired']);
        $this->assertGuest();
    }

    // ── settings and team ────────────────────────────────────────────

    #[Test]
    public function settings_shows_the_sso_state_to_admins_and_team_marks_linked_accounts(): void
    {
        config()->set('cipi-gui.sso.domains', 'acme.com');
        $this->member(['sso_subject' => 'sub-mario']);
        $this->actingAs($this->admin());

        $this->get('/settings')->assertOk()
            ->assertSee('Single sign-on')
            ->assertSee('Microsoft Entra ID')
            ->assertSee('login.microsoftonline.com')
            ->assertSee('acme.com')
            ->assertSee(route('cipi-gui.sso.callback'))
            ->assertSee('cipi gui sso set');
        $this->get('/team')->assertOk()->assertSee('>SSO<', false);

        config()->set('cipi-gui.sso.issuer', '');
        $this->get('/settings')->assertOk()->assertSee('Single sign-on')->assertSee('everyone signs in with email and password');

        $this->actingAs($this->member(['email' => 'user@cipi.local']));
        $this->get('/settings')->assertOk()->assertDontSee('Single sign-on');
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** What the redirect left in the session. */
    private function handshake(): array
    {
        return ['cipi_gui_sso_state' => 'state-1', 'cipi_gui_sso_nonce' => 'nonce-1', 'cipi_gui_sso_verifier' => 'verifier-1'];
    }

    /** Discovery, token and userinfo endpoints of a fake provider; Mario unless told otherwise. */
    private function fakeProvider(array $userinfo = [], array $idToken = [], mixed $tokenResponse = null): void
    {
        $this->userinfo = $userinfo + ['sub' => 'sub-mario', 'email' => 'mario@cipi.local', 'email_verified' => true, 'name' => 'Mario Rossi'];
        $this->idToken = $idToken + ['iss' => self::ISSUER, 'aud' => 'panel-client', 'sub' => $this->userinfo['sub'], 'nonce' => 'nonce-1', 'exp' => time() + 300];
        $this->tokenResponse = $tokenResponse;

        // Http::fake() appends stubs and the first match wins, so the stubs are registered once and read the properties above per request.
        if ($this->providerFaked) {
            return;
        }
        $this->providerFaked = true;

        Http::fake(fn (Request $request) => match ($request->url()) {
            self::ISSUER.'/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => 'https://idp.example.com/authorize',
                'token_endpoint' => 'https://idp.example.com/token',
                'userinfo_endpoint' => 'https://idp.example.com/userinfo',
            ]),
            'https://idp.example.com/token' => $this->tokenResponse ?? Http::response(['access_token' => 'at-1', 'token_type' => 'Bearer', 'id_token' => $this->jwt($this->idToken)]),
            'https://idp.example.com/userinfo' => Http::response($this->userinfo),
            default => null,
        });
    }

    /** An unsigned JWT: the panel reads the payload and never checks the signature. */
    private function jwt(array $claims): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256', 'kid' => 'k1']).'.'.$encode($claims).'.not-a-signature';
    }
}
