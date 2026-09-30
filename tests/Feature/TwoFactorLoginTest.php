<?php

namespace Tests\Feature;

use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    private function twoFactorUser(): User
    {
        return User::factory()->create([
            'email' => 'twofactor@example.com',
            'password' => Hash::make('Password123'),
            'email_verified_at' => now(),
            'two_factor_enabled' => true,
        ]);
    }

    private function cacheKeyFor(User $user): string
    {
        return "2fa_{$user->id}_{$user->email}";
    }

    /**
     * Opening the 2FA setup used to set two_factor_enabled straight away, so
     * a user who looked at the QR code and closed the app was gated behind
     * an emailed code at their next login. Only a confirmed setup gates.
     */
    public function test_starting_2fa_setup_does_not_gate_login_until_confirmed()
    {
        Mail::fake();
        $user = User::factory()->create([
            'email' => 'setup@example.com',
            'password' => Hash::make('Password123'),
            'email_verified_at' => now(),
        ]);
        $credentials = ['email' => $user->email, 'password' => 'Password123'];
        // A real token rather than actingAs(): actingAs swaps the default
        // guard for Sanctum's, and the login endpoint then cannot attempt().
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/2fa/enable')->assertStatus(200);

        $this->withToken($token)->getJson('/api/2fa/status')
            ->assertJsonPath('data.two_factor_enabled', false)
            ->assertJsonPath('data.two_factor_setup', true);

        $this->asGuest()->postJson('/api/login', $credentials)
            ->assertStatus(200)
            ->assertJsonPath('data.requires_2fa', false);

        // Cancelling the half-finished setup is still possible.
        $this->withToken($token)->deleteJson('/api/2fa/enable')->assertStatus(200);
        $this->assertNull($user->fresh()->two_factor_secret);

        // A confirmed setup does gate.
        $this->withToken($token)->postJson('/api/2fa/enable')->assertStatus(200);
        $code = (new \PragmaRX\Google2FA\Google2FA())->getCurrentOtp($user->fresh()->two_factor_secret);
        $this->withToken($token)->postJson('/api/2fa/confirm', ['code' => $code])->assertStatus(200);

        $this->asGuest()->postJson('/api/login', $credentials)
            ->assertStatus(200)
            ->assertJsonPath('data.requires_2fa', true);
    }

    /**
     * Within one test the app instance persists across requests, so after an
     * auth:sanctum request the default guard stays "sanctum" and the login
     * endpoint's Auth::attempt() has no such method. Real requests each get a
     * fresh app; this puts the test back in that state.
     */
    private function asGuest(): static
    {
        $this->flushHeaders();
        $this->app['auth']->shouldUse('web');

        return $this;
    }

    public function test_login_with_2fa_enabled_sends_code_and_caches_it()
    {
        Mail::fake();
        $user = $this->twoFactorUser();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.requires_2fa', true);

        Mail::assertSent(TwoFactorCodeMail::class);
        $this->assertNotNull(Cache::get($this->cacheKeyFor($user)));
    }

    /**
     * The regression this guards: delivery used to happen before the code was
     * cached, inside the login try/catch. An SMTP outage therefore threw before
     * Cache::put ever ran, so the generic handler returned 500 and no code
     * existed anywhere — every 2FA user was locked out entirely.
     */
    public function test_mail_failure_returns_503_and_still_caches_the_code()
    {
        $user = $this->twoFactorUser();

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP unavailable'));

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ]);

        $response->assertStatus(503)
            ->assertJsonPath('code', 'two_factor_delivery_failed');

        // The code must survive the delivery failure so the user can request a
        // resend rather than being forced to restart the login.
        $this->assertNotNull(Cache::get($this->cacheKeyFor($user)));
    }

    public function test_mail_failure_is_not_reported_as_a_generic_login_failure()
    {
        $user = $this->twoFactorUser();

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP unavailable'));

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ]);

        // Specifically not 500 "Login failed. Please try again." — the credentials
        // were valid and the user should not be told their login was wrong.
        $response->assertStatus(503);
        $this->assertNotSame('Login failed. Please try again.', $response->json('message'));
    }

    public function test_wrong_password_still_returns_401_not_503()
    {
        Mail::fake();
        $user = $this->twoFactorUser();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'WrongPassword123',
        ]);

        $response->assertStatus(401);
        Mail::assertNothingSent();
        $this->assertNull(Cache::get($this->cacheKeyFor($user)));
    }
}
