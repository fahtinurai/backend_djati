<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverApiFlowTest extends TestCase
{
    use RefreshDatabase;

    private function createDriver(): User
    {
        return User::factory()->create([
            'role' => 'driver',
        ]);
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

        $this->assertNotEquals(401, $status, "{$message}. Driver dianggap belum login.");
        $this->assertNotEquals(403, $status, "{$message}. Driver tidak punya akses.");
    }

    public function test_driver_can_verify_vehicle_endpoint()
    {
        $driver = $this->createDriver();

        Sanctum::actingAs($driver);

        $response = $this->postJson('/api/driver/vehicles/verify', [
            'plate_number' => 'B 1234 ABC',
        ]);

        $this->assertHandled($response, 'Endpoint verify vehicle bermasalah');
    }

    public function test_driver_can_create_vehicle_daily_log_endpoint()
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

        $this->assertHandled($response, 'Endpoint create vehicle daily log bermasalah');
    }

    public function test_driver_can_create_damage_report_endpoint()
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

        $this->assertHandled($response, 'Endpoint create damage report bermasalah');
    }

    public function test_driver_can_update_damage_report_endpoint()
    {
        $driver = $this->createDriver();

        Sanctum::actingAs($driver);

        $response = $this->putJson('/api/driver/damage-reports/999999', [
            'title' => 'Update kerusakan mesin',
            'description' => 'Update deskripsi kerusakan',
            'damage_level' => 'high',
            'location' => 'Pool kendaraan',
        ]);

        $this->assertHandled($response, 'Endpoint update damage report bermasalah');
    }

    public function test_driver_can_delete_damage_report_endpoint()
    {
        $driver = $this->createDriver();

        Sanctum::actingAs($driver);

        $response = $this->deleteJson('/api/driver/damage-reports/999999');

        $this->assertHandled($response, 'Endpoint delete damage report bermasalah');
    }

    public function test_driver_can_create_booking_from_damage_report_endpoint()
    {
        $driver = $this->createDriver();

        Sanctum::actingAs($driver);

        $response = $this->postJson('/api/driver/damage-reports/999999/booking', [
            'requested_date' => now()->addDay()->toDateString(),
            'note' => 'Request service booking dari testing',
        ]);

        $this->assertHandled($response, 'Endpoint create booking dari damage report bermasalah');
    }

    public function test_driver_can_show_booking_by_damage_report_endpoint()
    {
        $driver = $this->createDriver();

        Sanctum::actingAs($driver);

        $response = $this->getJson('/api/driver/damage-reports/999999/booking');

        $this->assertHandled($response, 'Endpoint show booking by damage report bermasalah');
    }

    public function test_driver_can_cancel_booking_endpoint()
    {
        $driver = $this->createDriver();

        Sanctum::actingAs($driver);

        $response = $this->postJson('/api/driver/bookings/999999/cancel', [
            'reason' => 'Cancel dari testing',
        ]);

        $this->assertHandled($response, 'Endpoint cancel booking bermasalah');
    }

    public function test_driver_can_update_service_reminder_endpoint()
    {
        $driver = $this->createDriver();
        $vehicleId = $this->createVehicleForDriver($driver);

        Sanctum::actingAs($driver);

        $response = $this->putJson('/api/driver/vehicles/' . $vehicleId . '/service-reminder', [
            'last_service_date' => now()->subMonth()->toDateString(),
            'next_service_date' => now()->addMonth()->toDateString(),
            'service_interval_km' => 5000,
        ]);

        $this->assertHandled($response, 'Endpoint update service reminder bermasalah');
    }

    public function test_driver_can_show_technician_review_endpoint()
    {
        $driver = $this->createDriver();

        Sanctum::actingAs($driver);

        $response = $this->getJson('/api/driver/damage-reports/999999/review');

        $this->assertHandled($response, 'Endpoint show technician review bermasalah');
    }

    public function test_driver_can_create_technician_review_endpoint()
    {
        $driver = $this->createDriver();

        Sanctum::actingAs($driver);

        $response = $this->postJson('/api/driver/damage-reports/999999/review', [
            'rating' => 5,
            'comment' => 'Teknisi bekerja dengan baik',
        ]);

        $this->assertHandled($response, 'Endpoint create technician review bermasalah');
    }
}