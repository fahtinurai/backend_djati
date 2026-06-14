<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TechnicianServiceJobTest extends TestCase
{
    use RefreshDatabase;

    private function createTechnician(): User
    {
        return User::factory()->create(['role' => 'teknisi']);
    }

    private function assertHandled($response, string $message): void
    {
        $status = $response->getStatusCode();

        $this->assertTrue(
            $status < 500,
            "{$message}. Status: {$status}. Response: " . $response->getContent()
        );

        $this->assertNotEquals(401, $status, "{$message}. Teknisi belum login.");
        $this->assertNotEquals(403, $status, "{$message}. Teknisi tidak punya akses.");
    }

    public function test_technician_can_view_service_jobs()
    {
        Sanctum::actingAs($this->createTechnician());

        $response = $this->getJson('/api/technician/service-jobs');

        $this->assertHandled($response, 'Teknisi gagal melihat service jobs');
    }

    public function test_technician_can_view_service_jobs_with_status_filter()
    {
        Sanctum::actingAs($this->createTechnician());

        $response = $this->getJson('/api/technician/service-jobs?status=active');

        $this->assertHandled($response, 'Teknisi gagal filter service jobs active');
    }

    public function test_technician_can_access_service_job_detail_endpoint()
    {
        Sanctum::actingAs($this->createTechnician());

        $response = $this->getJson('/api/technician/service-jobs/999999');

        $this->assertHandled($response, 'Endpoint detail service job bermasalah');
    }

    public function test_technician_can_access_start_service_job_endpoint()
    {
        Sanctum::actingAs($this->createTechnician());

        $response = $this->postJson('/api/technician/service-jobs/999999/start', [
            'note' => 'Start service job testing',
        ]);

        $this->assertHandled($response, 'Endpoint start service job bermasalah');
    }

    public function test_technician_can_access_complete_service_job_endpoint()
    {
        Sanctum::actingAs($this->createTechnician());

        $response = $this->postJson('/api/technician/service-jobs/999999/complete', [
            'note' => 'Complete service job testing',
            'repair_note' => 'Perbaikan selesai',
        ]);

        $this->assertHandled($response, 'Endpoint complete service job bermasalah');
    }

    public function test_technician_can_view_legacy_jobs()
    {
        Sanctum::actingAs($this->createTechnician());

        $response = $this->getJson('/api/technician/jobs');

        $this->assertHandled($response, 'Teknisi gagal melihat legacy jobs');
    }

    public function test_technician_can_access_legacy_job_detail_endpoint()
    {
        Sanctum::actingAs($this->createTechnician());

        $response = $this->getJson('/api/technician/jobs/999999');

        $this->assertHandled($response, 'Endpoint detail legacy job bermasalah');
    }

    public function test_technician_can_access_start_legacy_job_endpoint()
    {
        Sanctum::actingAs($this->createTechnician());

        $response = $this->postJson('/api/technician/jobs/999999/start', [
            'note' => 'Start legacy job testing',
        ]);

        $this->assertHandled($response, 'Endpoint start legacy job bermasalah');
    }

    public function test_technician_can_access_complete_legacy_job_endpoint()
    {
        Sanctum::actingAs($this->createTechnician());

        $response = $this->postJson('/api/technician/jobs/999999/complete', [
            'note' => 'Complete legacy job testing',
            'repair_note' => 'Perbaikan selesai',
        ]);

        $this->assertHandled($response, 'Endpoint complete legacy job bermasalah');
    }
}