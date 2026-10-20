<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_login_issues_revocable_expiring_token(): void
    {
        $u = User::factory()->create(['email' => 'staff@example.com', 'password' => 'Strong-test-password-2026', 'role' => 'staff_admin', 'active' => true]);
        $r = $this->postJson('/api/v1/auth/mobile/login', ['email' => $u->email, 'password' => 'Strong-test-password-2026', 'device_name' => 'Test Android'])->assertOk();
        $token = $r->json('data.token');
        $this->assertNotEmpty($token);
        $this->assertNotNull($u->tokens()->first()->expires_at);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $u->email);
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $u->tokens()->count());
    }

    public function test_bad_credentials_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/mobile/login', ['email' => 'nobody@example.com', 'password' => 'incorrect', 'device_name' => 'Phone'])->assertUnprocessable();
    }

    public function test_login_screen_is_available(): void
    {
        $this->get('/')->assertOk()->assertSee('Cox');
    }
}
