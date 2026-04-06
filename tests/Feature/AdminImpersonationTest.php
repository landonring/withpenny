<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    public function test_allowlisted_admin_email_can_start_and_stop_impersonation(): void
    {
        config()->set('services.admin.email', 'landon@example.com');

        $admin = User::factory()->create([
            'role' => 'user',
            'email' => 'landon@example.com',
        ]);
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->postJson("/admin/impersonate/{$target->id}")
            ->assertOk()
            ->assertJsonPath('user.id', $target->id)
            ->assertJsonPath('impersonating', true);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.id', $target->id)
            ->assertJsonPath('impersonating', true);

        $this->postJson('/admin/impersonate/stop')
            ->assertOk()
            ->assertJsonPath('user.id', $admin->id)
            ->assertJsonPath('impersonating', false);
    }
}
