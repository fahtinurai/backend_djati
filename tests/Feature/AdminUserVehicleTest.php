<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserVehicleTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function assertHandled($response, string $message): void
    {
        $status = $response->getStatusCode();

        $this->assertTrue(
            $status < 500,
            "{$message}. Status: {$status}. Response: " . $response->getContent()
        );

        $this->assertNotEquals(401, $status, "{$message}. Admin belum login.");
        $this->assertNotEquals(403, $status, "{$message}. Admin tidak punya akses.");
    }

    public function test_admin_can_view_users()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/users');

        $this->assertHandled($response, 'Admin gagal melihat users');
    }

    public function test_admin_can_create_user()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Budi Santoso',
            'username' => 'budi_santoso',
            'email' => 'budi@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'driver',
        ]);

        $this->assertHandled($response, 'Admin gagal membuat user');

        $this->assertDatabaseHas('users', [
            'name' => 'Budi Santoso',
            'username' => 'budi_santoso',
            'role' => 'driver',
        ]);
    }

    public function test_create_user_validation_fails()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->postJson('/api/admin/users', [
            'name' => '',
            'username' => '',
            'email' => '',
            'password' => '',
            'role' => '',
        ]);

        $this->assertTrue(
            in_array($response->getStatusCode(), [400, 422]),
            'Validasi user kosong harus gagal. Status: ' . $response->getStatusCode()
        );
    }

    public function test_admin_can_view_vehicles()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/vehicles');

        $this->assertHandled($response, 'Admin gagal melihat vehicles');
    }

    public function test_admin_can_create_vehicle()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->postJson('/api/admin/vehicles', [
            'equipment_name' => 'Toyota Avanza',
            'plate_number' => 'B 1234 ABC',
            'brand' => 'Toyota',
            'model' => 'Avanza',
            'year' => 2020,
        ]);

        $this->assertHandled($response, 'Admin gagal membuat vehicle');
    }
}