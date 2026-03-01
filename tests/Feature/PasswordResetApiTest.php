<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_forgot_password_returns_ok_for_existing_email(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/forgot-password', [
            'email' => $user->email,
        ])->assertOk()
            ->assertJsonPath('message', 'If that email exists, we sent reset instructions.');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_returns_ok_for_unknown_email(): void
    {
        $this->postJson('/api/forgot-password', [
            'email' => 'unknown@example.com',
        ])->assertOk()
            ->assertJsonPath('message', 'If that email exists, we sent reset instructions.');
    }

    public function test_reset_password_updates_user_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password-123'),
        ]);

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk()
            ->assertJsonPath('message', 'Password reset complete. You can log in now.');

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));
    }
}
