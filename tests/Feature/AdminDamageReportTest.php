<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDamageReportTest extends TestCase
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

    public function test_admin_can_view_damage_reports()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/damage-reports');

        $this->assertHandled($response, 'Admin gagal melihat damage reports');
    }

    public function test_admin_can_view_follow_up_list()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/damage-reports/follow-ups/list');

        $this->assertHandled($response, 'Admin gagal melihat follow up list');
    }

    public function test_admin_can_view_finished_repairs()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/damage-reports/finished-repairs');

        $this->assertHandled($response, 'Admin gagal melihat finished repairs');
    }

    public function test_admin_can_access_damage_report_detail_endpoint()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/damage-reports/999999');

        $this->assertHandled($response, 'Endpoint detail damage report bermasalah');
    }

    public function test_admin_can_access_complete_damage_report_endpoint()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->postJson('/api/admin/damage-reports/999999/complete', [
            'note' => 'Complete damage report testing',
        ]);

        $this->assertHandled($response, 'Endpoint complete damage report bermasalah');
    }

    public function test_admin_can_access_approve_follow_up_endpoint()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->postJson('/api/admin/damage-reports/999999/approve-follow-up', [
            'note' => 'Approve follow up testing',
        ]);

        $this->assertHandled($response, 'Endpoint approve follow up bermasalah');
    }

    public function test_admin_can_access_store_finished_repair_endpoint()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->postJson('/api/admin/damage-reports/999999/store-finished-repair', [
            'repair_note' => 'Finished repair testing',
            'finished_at' => now()->toDateTimeString(),
        ]);

        $this->assertHandled($response, 'Endpoint store finished repair bermasalah');
    }
}