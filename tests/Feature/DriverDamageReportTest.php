<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverDamageReportTest extends TestCase
{
    use RefreshDatabase;

    private function createDriver(): User
    {
        return User::factory()->create(['role' => 'driver']);
    }

    private function createVehicleForDriver(User $driver): int
    {
        $vehicleId = DB::table('vehicles')->insertGetId([
            'equipment_name' => 'Toyota Avanza',
            'plate_number' => 'B 1234 ABC',
            'brand' => 'Toyota',
            'model' => 'Avanza',
            'year' => 2020,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('vehicle_assignments')->insert([
            'vehicle_id' => $vehicleId,
            'driver_id' => $driver->id,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $vehicleId;
    }

    private function assertHandled($response, string $message): void
    {
        $status = $response->getStatusCode();

        $this->assertTrue(
            $status < 500,
            "{$message}. Status: {$status}. Response: " . $response->getContent()
        );

        $this->assertNotEquals(401, $status, "{$message}. Driver belum login.");
        $this->assertNotEquals(403, $status, "{$message}. Driver tidak punya akses.");
    }

    public function test_driver_can_view_damage_reports()
    {
        Sanctum::actingAs($this->createDriver());

        $response = $this->getJson('/api/driver/damage-reports');

        $this->assertHandled($response, 'Driver gagal melihat damage reports');
    }

    public function test_driver_can_create_damage_report()
    {
        $driver = $this->createDriver();
        $vehicleId = $this->createVehicleForDriver($driver);

        Sanctum::actingAs($driver);

        $response = $this->postJson('/api/driver/damage-reports', [
            'vehicle_id' => $vehicleId,
            'title' => 'Kerusakan mesin',
            'description' => 'Mesin mengeluarkan suara tidak normal',
            'damage_level' => 'medium',
            'location' => 'Garasi',
        ]);

        $this->assertHandled($response, 'Driver gagal membuat damage report');
    }

    public function test_driver_can_access_damage_report_detail_endpoint()
    {
        Sanctum::actingAs($this->createDriver());

        $response = $this->getJson('/api/driver/damage-reports/999999');

        $this->assertHandled($response, 'Endpoint detail damage report driver bermasalah');
    }

    public function test_driver_can_access_update_damage_report_endpoint()
    {
        Sanctum::actingAs($this->createDriver());

        $response = $this->putJson('/api/driver/damage-reports/999999', [
            'title' => 'Update kerusakan mesin',
            'description' => 'Update deskripsi kerusakan',
            'damage_level' => 'high',
            'location' => 'Pool kendaraan',
        ]);

        $this->assertHandled($response, 'Endpoint update damage report driver bermasalah');
    }

    public function test_driver_can_access_delete_damage_report_endpoint()
    {
        Sanctum::actingAs($this->createDriver());

        $response = $this->deleteJson('/api/driver/damage-reports/999999');

        $this->assertHandled($response, 'Endpoint delete damage report driver bermasalah');
    }
}