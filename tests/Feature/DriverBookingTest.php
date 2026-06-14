<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverBookingTest extends TestCase
{
    use RefreshDatabase;

    private function createDriver(): User
    {
        return User::factory()->create(['role' => 'driver']);
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

    public function test_driver_can_view_bookings()
    {
        Sanctum::actingAs($this->createDriver());

        $response = $this->getJson('/api/driver/bookings');

        $this->assertHandled($response, 'Driver gagal melihat bookings');
    }

    public function test_driver_can_access_create_booking_endpoint()
    {
        Sanctum::actingAs($this->createDriver());

        $response = $this->postJson('/api/driver/damage-reports/999999/booking', [
            'requested_date' => now()->addDay()->toDateString(),
            'note' => 'Booking testing',
        ]);

        $this->assertHandled($response, 'Endpoint create booking driver bermasalah');
    }

    public function test_driver_can_access_show_booking_by_damage_report_endpoint()
    {
        Sanctum::actingAs($this->createDriver());

        $response = $this->getJson('/api/driver/damage-reports/999999/booking');

        $this->assertHandled($response, 'Endpoint show booking by damage report bermasalah');
    }

    public function test_driver_can_access_cancel_booking_endpoint()
    {
        Sanctum::actingAs($this->createDriver());

        $response = $this->postJson('/api/driver/bookings/999999/cancel', [
            'reason' => 'Cancel booking testing',
        ]);

        $this->assertHandled($response, 'Endpoint cancel booking driver bermasalah');
    }
}