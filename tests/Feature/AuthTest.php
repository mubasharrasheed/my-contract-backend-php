<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Notification;
use App\Notifications\ResetPinNotification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // clear any leftover session state between tests
        $this->flushSession();
    }

    public function test_register_creates_user_and_returns_token()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure(['user', 'token']);

        $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
    }

    public function test_login_with_valid_credentials_can_get_token()
    {
        $user = User::factory()->create([
            'password' => Hash::make('mypassword'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'mypassword',
        ]);

        $response->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_logout_revokes_token()
    {
        // make sure any previous login session is gone
        $this->flushSession();

        $user = User::factory()->create();

        // create a real personal access token and use it for auth
        $token = $user->createToken('test')->plainTextToken;

        // token should exist in database before logout
        [$id, $plain] = explode('|', $token, 2);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $id,
            'token' => hash('sha256', $plain),
        ]);

        $this->withHeader('Authorization','Bearer '.$token)
             ->postJson('/api/logout')
             ->assertOk();

        // token should be removed or invalidated
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $id,
        ]);

        // clear session so we don't accidentally authenticate via web guard
        $this->flushSession();

        // refresh application to drop any cached authentication state
        $this->refreshApplication();

        // subsequent request with the same token should be unauthorized
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
             ->getJson('/api/user');

        // debug output
        $response->dump();

        $response->assertStatus(401);
    }

    public function test_forgot_password_sends_link()
    {
        $user = User::factory()->create();

        Notification::fake();

        $response = $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertOk()->assertJsonStructure(['message']);

        Notification::assertSentTo(
            $user,
            ResetPinNotification::class,
            function ($notification, $channels) {
                return is_string($notification->pin) && strlen($notification->pin) === 6;
            }
        );
    }

    public function test_reset_password_using_token()
    {
        $user = User::factory()->create();

        Notification::fake();

        // request a PIN using the forgot endpoint and capture it from notification
        $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ])->assertOk();

        $capturedPin = null;
        Notification::assertSentTo($user, ResetPinNotification::class, function ($notification) use (&$capturedPin) {
            $capturedPin = $notification->pin;
            return true;
        });

        $pin = $capturedPin;

        // verify the pin to get a reset token
        $verify = $this->postJson('/api/verify-pin', [
            'email' => $user->email,
            'pin' => $pin,
        ])->assertOk()->json();

        $this->assertArrayHasKey('reset_token', $verify);
        $resetToken = $verify['reset_token'];

        $new = 'newpassword123';

        $response = $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => $new,
            'password_confirmation' => $new,
        ]);

        $response->assertOk();

        $this->assertTrue(Hash::check($new, $user->fresh()->password));
    }
}
