<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
        ]);
    }

    private function driverGetRoutes(): array
    {
        return [
            '/api/driver/my-vehicle',
            '/api/driver/vehicles',
            '/api/driver/vehicle-daily-logs',
            '/api/driver/damage-reports',
            '/api/driver/bookings',
        ];
    }

    public function test_guest_cannot_access_driver_routes()
    {
        foreach ($this->driverGetRoutes() as $route) {
            $response = $this->getJson($route);

            $this->assertEquals(
                401,
                $response->getStatusCode(),
                "Guest masih bisa mengakses route driver: {$route}"
            );
        }
    }

    public function test_admin_cannot_access_driver_routes()
    {
        $admin = $this->createUser('admin');

        Sanctum::actingAs($admin);

        foreach ($this->driverGetRoutes() as $route) {
            $response = $this->getJson($route);

            $this->assertEquals(
                403,
                $response->getStatusCode(),
                "Admin masih bisa mengakses route driver: {$route}"
            );
        }
    }

    public function test_technician_cannot_access_driver_routes()
    {
        $technician = $this->createUser('teknisi');

        Sanctum::actingAs($technician);

        foreach ($this->driverGetRoutes() as $route) {
            $response = $this->getJson($route);

            $this->assertEquals(
                403,
                $response->getStatusCode(),
                "Teknisi masih bisa mengakses route driver: {$route}"
            );
        }
    }

    public function test_driver_can_access_driver_routes()
    {
        $driver = $this->createUser('driver');

        Sanctum::actingAs($driver);

        foreach ($this->driverGetRoutes() as $route) {
            $response = $this->getJson($route);
            $status = $response->getStatusCode();

            $this->assertTrue(
                in_array($status, [200, 404]),
                "Driver gagal mengakses route: {$route}. Status: {$status}. Response: " . $response->getContent()
            );
        }
    }
}