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
