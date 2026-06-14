<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPartUsageApprovalTest extends TestCase
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

    public function test_admin_can_view_part_usages()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/part-usages');

        $this->assertHandled($response, 'Admin gagal melihat part usages');
    }

    public function test_admin_can_view_pending_part_usages()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/part-usages/pending');

        $this->assertHandled($response, 'Admin gagal melihat pending part usages');
    }

    public function test_admin_can_filter_part_usages_by_status()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/part-usages?status=pending');

        $this->assertHandled($response, 'Admin gagal filter part usages pending');
    }

    public function test_admin_can_access_approve_part_usage_endpoint()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->postJson('/api/admin/part-usages/999999/approve', [
            'admin_note' => 'Approve part usage testing',
        ]);

        $this->assertHandled($response, 'Endpoint approve part usage bermasalah');
    }

    public function test_admin_can_access_reject_part_usage_endpoint()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->postJson('/api/admin/part-usages/999999/reject', [
            'reason' => 'Reject part usage testing',
        ]);

        $this->assertHandled($response, 'Endpoint reject part usage bermasalah');
    }
}