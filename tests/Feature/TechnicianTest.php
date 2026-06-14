<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TechnicianTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
        ]);
    }

    private function technicianGetRoutes(): array
    {
        return [
            '/api/technician/damage-reports',
            '/api/technician/my-responses',
            '/api/technician/service-jobs',
            '/api/technician/jobs',
            '/api/technician/parts',
            '/api/technician/my-part-usages',
            '/api/technician/reviews',
        ];
    }

    public function test_guest_cannot_access_technician_routes()
    {
        foreach ($this->technicianGetRoutes() as $route) {
            $response = $this->getJson($route);

            $this->assertEquals(
                401,
                $response->getStatusCode(),
                "Guest masih bisa mengakses route teknisi: {$route}"
            );
        }
    }

    public function test_admin_cannot_access_technician_routes()
    {
        $admin = $this->createUser('admin');

        Sanctum::actingAs($admin);

        foreach ($this->technicianGetRoutes() as $route) {
            $response = $this->getJson($route);

            $this->assertEquals(
                403,
                $response->getStatusCode(),
                "Admin masih bisa mengakses route teknisi: {$route}"
            );
        }
    }

    public function test_driver_cannot_access_technician_routes()
    {
        $driver = $this->createUser('driver');

        Sanctum::actingAs($driver);

        foreach ($this->technicianGetRoutes() as $route) {
            $response = $this->getJson($route);

            $this->assertEquals(
                403,
                $response->getStatusCode(),
                "Driver masih bisa mengakses route teknisi: {$route}"
            );
        }
    }

    public function test_technician_can_access_technician_routes()
    {
        $technician = $this->createUser('teknisi');

        Sanctum::actingAs($technician);

        foreach ($this->technicianGetRoutes() as $route) {
            $response = $this->getJson($route);
            $status = $response->getStatusCode();

            $this->assertTrue(
                in_array($status, [200, 404]),
                "Teknisi gagal mengakses route: {$route}. Status: {$status}. Response: " . $response->getContent()
            );
        }
    }
}