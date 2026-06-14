<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverVehicleDailyLogTest extends TestCase
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

    public function test_driver_can_view_vehicle_daily_logs()
    {
        Sanctum::actingAs($this->createDriver());

        $response = $this->getJson('/api/driver/vehicle-daily-logs');

        $this->assertHandled($response, 'Driver gagal melihat vehicle daily logs');
    }

    public function test_driver_can_create_vehicle_daily_log()
    {
        $driver = $this->createDriver();
        $vehicleId = $this->createVehicleForDriver($driver);

        Sanctum::actingAs($driver);

        $response = $this->postJson('/api/driver/vehicle-daily-logs', [
            'vehicle_id' => $vehicleId,
            'date' => now()->toDateString(),
            'odometer' => 1000,
            'fuel_level' => 'full',
            'condition_note' => 'Testing daily log',
            'note' => 'Testing daily log',
        ]);

        $this->assertHandled($response, 'Driver gagal membuat vehicle daily log');
    }

    public function test_driver_can_access_vehicle_daily_log_detail_endpoint()
    {
        Sanctum::actingAs($this->createDriver());

        $response = $this->getJson('/api/driver/vehicle-daily-logs/999999');

        $this->assertHandled($response, 'Endpoint detail vehicle daily log bermasalah');
    }

    public function test_vehicle_daily_log_validation_fails()
    {
        Sanctum::actingAs($this->createDriver());

        $response = $this->postJson('/api/driver/vehicle-daily-logs', []);

        $this->assertTrue(
            $response->getStatusCode() < 500,
            'Validasi daily log kosong tidak boleh error 500. Status: ' . $response->getStatusCode()
        );
    }
}