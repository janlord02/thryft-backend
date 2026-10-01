<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A mail outage during signup must not leave a half-registered account that
 * cannot be retried: the account is created, the response says the email did
 * not go out, and the verification screen's resend covers the rest.
 */
class SignupMailFailureTest extends TestCase
{
    use RefreshDatabase;

    private function breakMail(): void
    {
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('send')->andThrow(new \RuntimeException('535 SMTP auth failed'));
        });
    }

    public function test_shopper_signup_survives_a_mail_failure()
    {
        $this->breakMail();

        $this->postJson('/api/register', [
            'email' => 'sam@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
            ->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonFragment(['message' => 'Account created, but the verification email could not be sent. Use "Resend" on the next screen.']);

        $this->assertNotNull(User::where('email', 'sam@example.com')->first());
    }

    public function test_business_signup_survives_a_mail_failure()
    {
        $this->breakMail();

        $this->postJson('/api/register-business', [
            'firstname' => 'Bea',
            'lastname' => 'Baker',
            'email' => 'bea@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'business_name' => 'Bea Bakes',
            'address' => '9 Oak St',
            'city' => 'Davao',
            'state' => 'DS',
            'zipcode' => '8000',
            'country' => 'PH',
        ])
            ->assertStatus(201)
            ->assertJsonPath('status', 'success');

        $this->assertNotNull(User::where('email', 'bea@example.com')->first());
    }
}
