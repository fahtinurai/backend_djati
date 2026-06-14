<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
        ]);
    }

    private function createDriver(): User
    {
        return User::factory()->create([
            'role' => 'driver',
        ]);
    }

    private function createTechnician(): User
    {
        return User::factory()->create([
            'role' => 'teknisi',
        ]);
    }

    private function adminGetRoutes(): array
    {
        return [
            '/api/admin/dashboard',

            '/api/admin/users',
            '/api/admin/vehicles',
            '/api/admin/vehicle-assignments',

            '/api/admin/damage-reports',
            '/api/admin/damage-reports/finished-repairs',
            '/api/admin/damage-reports/follow-ups/list',

            '/api/admin/parts',
            '/api/admin/stock-movements',

            '/api/admin/part-usages',
            '/api/admin/part-usages/pending',

            '/api/admin/repairs',

            '/api/admin/transactions',

            '/api/admin/bookings',
            '/api/admin/bookings?status=requested',
            '/api/admin/bookings?status=all',

            '/api/admin/technicians',
        ];
    }

    public function test_guest_cannot_access_admin_routes()
    {
        foreach ($this->adminGetRoutes() as $route) {
            $response = $this->getJson($route);

            $this->assertEquals(
                401,
                $response->getStatusCode(),
                "Guest masih bisa mengakses route admin: {$route}"
            );
        }
    }

    public function test_driver_cannot_access_admin_routes()
    {
        $driver = $this->createDriver();

        Sanctum::actingAs($driver);

        foreach ($this->adminGetRoutes() as $route) {
            $response = $this->getJson($route);

            $this->assertEquals(
                403,
                $response->getStatusCode(),
                "Driver masih bisa mengakses route admin: {$route}"
            );
        }
    }

    public function test_technician_cannot_access_admin_routes()
    {
        $technician = $this->createTechnician();

        Sanctum::actingAs($technician);

        foreach ($this->adminGetRoutes() as $route) {
            $response = $this->getJson($route);

            $this->assertEquals(
                403,
                $response->getStatusCode(),
                "Teknisi masih bisa mengakses route admin: {$route}"
            );
        }
    }

    public function test_admin_can_access_admin_routes()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        foreach ($this->adminGetRoutes() as $route) {
            $response = $this->getJson($route);

            $this->assertEquals(
                200,
                $response->getStatusCode(),
                "Admin gagal mengakses route: {$route}. Response: " . $response->getContent()
            );
        }
    }

    public function test_admin_can_access_profile()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/me');

        $response->assertStatus(200);
    }

    public function test_admin_can_logout()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/logout');

        $response->assertStatus(200);
    }

    public function test_admin_can_create_user()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Budi Santoso',
            'username' => 'budi_santoso',
            'email' => 'budi@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'driver',
        ]);

        $this->assertTrue(
            in_array($response->getStatusCode(), [200, 201]),
            "Gagal membuat user. Status: {$response->getStatusCode()}. Response: " . $response->getContent()
        );

        $this->assertDatabaseHas('users', [
            'name' => 'Budi Santoso',
            'username' => 'budi_santoso',
            'role' => 'driver',
        ]);
    }

    public function test_create_user_validation_fails()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/users', [
            'name' => '',
            'username' => '',
            'email' => '',
            'password' => '',
            'role' => '',
        ]);

        $response->assertStatus(422);
    }
}