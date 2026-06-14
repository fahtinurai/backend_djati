<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminBookingApprovalTest extends TestCase
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

    public function test_admin_can_view_booking_list()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/bookings');

        $this->assertHandled($response, 'Admin gagal melihat daftar booking');
    }

    public function test_admin_can_filter_requested_bookings()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/bookings?status=requested');

        $this->assertHandled($response, 'Admin gagal filter booking requested');
    }

    public function test_admin_can_filter_all_bookings()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/bookings?status=all');

        $this->assertHandled($response, 'Admin gagal filter semua booking');
    }

    public function test_admin_can_view_technician_dropdown()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/technicians');

        $this->assertHandled($response, 'Admin gagal melihat daftar teknisi');
    }

    public function test_admin_can_access_approve_booking_endpoint()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->postJson('/api/admin/bookings/999999/approve', [
            'technician_id' => 999999,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'estimated_finish_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'priority' => 'medium',
            'note_admin' => 'Approve booking testing',
        ]);

        $this->assertHandled($response, 'Endpoint approve booking bermasalah');
    }

    public function test_admin_can_access_reschedule_booking_endpoint()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->postJson('/api/admin/bookings/999999/reschedule', [
            'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'estimated_finish_at' => now()->addDays(4)->format('Y-m-d H:i:s'),
            'priority' => 'high',
            'note_admin' => 'Reschedule booking testing',
        ]);

        $this->assertHandled($response, 'Endpoint reschedule booking bermasalah');
    }

    public function test_admin_can_access_cancel_booking_endpoint()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->postJson('/api/admin/bookings/999999/cancel', [
            'reason' => 'Cancel booking testing',
        ]);

        $this->assertHandled($response, 'Endpoint cancel booking bermasalah');
    }
}