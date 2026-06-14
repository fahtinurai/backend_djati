<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FullMaintenanceFlowTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function assertHandled($response, string $message): void
    {
        $status = $response->getStatusCode();

        $this->assertTrue(
            $status < 500,
            "{$message}. Status: {$status}. Response: " . $response->getContent()
        );

        $this->assertNotEquals(401, $status, "{$message}. User belum login.");
        $this->assertNotEquals(403, $status, "{$message}. User tidak punya akses.");
    }

    public function test_maintenance_flow_routes_are_accessible_by_correct_roles()
    {
        $driver = $this->createUser('driver');
        $admin = $this->createUser('admin');
        $technician = $this->createUser('teknisi');

        Sanctum::actingAs($driver);

        $driverDamageReports = $this->getJson('/api/driver/damage-reports');
        $this->assertHandled($driverDamageReports, 'Driver gagal akses damage reports');

        $driverBookings = $this->getJson('/api/driver/bookings');
        $this->assertHandled($driverBookings, 'Driver gagal akses bookings');

        Sanctum::actingAs($admin);

        $adminBookings = $this->getJson('/api/admin/bookings?status=all');
        $this->assertHandled($adminBookings, 'Admin gagal akses bookings');

        $adminTechnicians = $this->getJson('/api/admin/technicians');
        $this->assertHandled($adminTechnicians, 'Admin gagal akses technicians');

        Sanctum::actingAs($technician);

        $technicianJobs = $this->getJson('/api/technician/service-jobs?status=all');
        $this->assertHandled($technicianJobs, 'Teknisi gagal akses service jobs');

        $technicianLegacyJobs = $this->getJson('/api/technician/jobs');
        $this->assertHandled($technicianLegacyJobs, 'Teknisi gagal akses legacy jobs');
    }
}