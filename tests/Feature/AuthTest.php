<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $role = 'driver'): User
    {
        return User::factory()->create([
            'name' => 'Test User',
            'username' => 'test_' . $role,
            'email' => $role . '@example.com',
            'password' => Hash::make('password123'),
            'role' => $role,
        ]);
    }

    public function test_user_can_login_with_valid_credentials()
    {
        $user = $this->createUser('admin');

        $response = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'password123',
        ]);

        if (! in_array($response->getStatusCode(), [200, 201])) {
            $response = $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'password123',
            ]);
        }

        $this->assertTrue(
            in_array($response->getStatusCode(), [200, 201]),
            "Login gagal. Status: {$response->getStatusCode()}. Response: " . $response->getContent()
        );
    }

    public function test_user_cannot_login_with_invalid_credentials()
    {
        $user = $this->createUser('admin');

        $response = $this->postJson('/api/login', [
            'username' => $user->username,
            'password' => 'password_salah',
        ]);

        $this->assertTrue(
            in_array($response->getStatusCode(), [401, 422]),
            "Login invalid harus ditolak. Status: {$response->getStatusCode()}. Response: " . $response->getContent()
        );
    }

    public function test_authenticated_user_can_access_profile()
    {
        $user = $this->createUser('admin');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/me');

        $response->assertStatus(200);
    }

    public function test_guest_cannot_access_profile()
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_logout()
    {
        $user = $this->createUser('admin');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/logout');

        $this->assertTrue(
            in_array($response->getStatusCode(), [200, 204]),
            "Logout gagal. Status: {$response->getStatusCode()}. Response: " . $response->getContent()
        );
    }
}