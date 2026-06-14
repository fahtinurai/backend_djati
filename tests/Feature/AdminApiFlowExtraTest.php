<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminApiFlowExtraTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
        ]);
    }

    private function assertHandledResponse($response, string $message): void
    {
        $status = $response->getStatusCode();

        $this->assertTrue(
            $status < 500,
            "{$message}. Status: {$status}. Response: " . $response->getContent()
        );

        $this->assertNotEquals(401, $status, "{$message}. Admin dianggap belum login.");
        $this->assertNotEquals(403, $status, "{$message}. Admin tidak punya akses.");
    }

    public function test_admin_can_create_vehicle_endpoint()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/vehicles', [
            'plate_number' => 'B 1234 ABC',
            'brand' => 'Toyota',
            'model' => 'Avanza',
            'year' => 2020,
            'status' => 'available',
        ]);

        $this->assertHandledResponse($response, 'Endpoint create vehicle admin bermasalah');
    }

    public function test_admin_can_create_part_endpoint()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/parts', [
            'name' => 'Oli Mesin',
            'sku' => 'OLI-001',
            'stock' => 10,
            'buy_price' => 50000,
        ]);

        $this->assertHandledResponse($response, 'Endpoint create part admin bermasalah');
    }

    public function test_admin_can_access_damage_report_detail_endpoint()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/damage-reports/999999');

        $this->assertHandledResponse($response, 'Endpoint show damage report admin bermasalah');
    }

    public function test_admin_can_complete_damage_report_endpoint()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/damage-reports/999999/complete', [
            'note' => 'Damage report completed from testing',
        ]);

        $this->assertHandledResponse($response, 'Endpoint complete damage report admin bermasalah');
    }

    public function test_admin_can_approve_follow_up_endpoint()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/damage-reports/999999/approve-follow-up', [
            'note' => 'Approve follow up from testing',
        ]);

        $this->assertHandledResponse($response, 'Endpoint approve follow up admin bermasalah');
    }

    public function test_admin_can_store_finished_repair_endpoint()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/damage-reports/999999/store-finished-repair', [
            'repair_note' => 'Finished repair testing',
            'finished_at' => now()->toDateTimeString(),
        ]);

        $this->assertHandledResponse($response, 'Endpoint store finished repair admin bermasalah');
    }

    public function test_admin_can_approve_part_usage_endpoint()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/part-usages/999999/approve', [
            'admin_note' => 'Approved from testing',
        ]);

        $this->assertHandledResponse($response, 'Endpoint approve part usage admin bermasalah');
    }

    public function test_admin_can_reject_part_usage_endpoint()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/part-usages/999999/reject', [
            'reason' => 'Rejected from testing',
        ]);

        $this->assertHandledResponse($response, 'Endpoint reject part usage admin bermasalah');
    }

    public function test_admin_can_create_transaction_endpoint()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/transactions', [
            'type' => 'expense',
            'category' => 'Testing',
            'amount' => 100000,
            'date' => now()->toDateString(),
            'note' => 'Transaction testing',
            'source' => 'manual',
        ]);

        $this->assertHandledResponse($response, 'Endpoint create transaction admin bermasalah');
    }

    public function test_admin_can_update_transaction_endpoint()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/admin/transactions/999999', [
            'type' => 'expense',
            'category' => 'Testing Updated',
            'amount' => 120000,
            'date' => now()->toDateString(),
            'note' => 'Update transaction testing',
            'source' => 'manual',
        ]);

        $this->assertHandledResponse($response, 'Endpoint update transaction admin bermasalah');
    }

    public function test_admin_can_delete_transaction_endpoint()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->deleteJson('/api/admin/transactions/999999');

        $this->assertHandledResponse($response, 'Endpoint delete transaction admin bermasalah');
    }

    public function test_admin_can_approve_booking_endpoint()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/bookings/999999/approve', [
            'technician_id' => 999999,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'estimated_finish_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'priority' => 'medium',
            'note_admin' => 'Approve booking testing',
        ]);

        $this->assertHandledResponse($response, 'Endpoint approve booking admin bermasalah');
    }

    public function test_admin_can_reschedule_booking_endpoint()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/bookings/999999/reschedule', [
            'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'estimated_finish_at' => now()->addDays(4)->format('Y-m-d H:i:s'),
            'priority' => 'high',
            'note_admin' => 'Reschedule booking testing',
        ]);

        $this->assertHandledResponse($response, 'Endpoint reschedule booking admin bermasalah');
    }

    public function test_admin_can_cancel_booking_endpoint()
    {
        $admin = $this->createAdmin();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/bookings/999999/cancel', [
            'reason' => 'Cancel booking testing',
        ]);

        $this->assertHandledResponse($response, 'Endpoint cancel booking admin bermasalah');
    }
}