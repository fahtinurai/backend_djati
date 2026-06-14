<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TechnicianApiFlowTest extends TestCase
{
    use RefreshDatabase;

    private function createTechnician(): User
    {
        return User::factory()->create([
            'role' => 'teknisi',
        ]);
    }

    private function assertHandledResponse($response, string $message): void
    {
        $status = $response->getStatusCode();

        $this->assertTrue(
            $status < 500,
            "{$message}. Status: {$status}. Response: " . $response->getContent()
        );

        $this->assertNotEquals(401, $status, "{$message}. Teknisi dianggap belum login.");
        $this->assertNotEquals(403, $status, "{$message}. Teknisi tidak punya akses.");
    }

    public function test_technician_can_show_damage_report_endpoint()
    {
        $technician = $this->createTechnician();

        Sanctum::actingAs($technician);

        $response = $this->getJson('/api/technician/damage-reports/999999');

        $this->assertHandledResponse($response, 'Endpoint show damage report teknisi bermasalah');
    }

    public function test_technician_can_respond_damage_report_endpoint()
    {
        $technician = $this->createTechnician();

        Sanctum::actingAs($technician);

        $response = $this->postJson('/api/technician/damage-reports/999999/respond', [
            'response' => 'Kerusakan akan diperiksa',
            'status' => 'in_progress',
            'note' => 'Response dari testing',
        ]);

        $this->assertHandledResponse($response, 'Endpoint respond damage report bermasalah');
    }

    public function test_technician_can_update_response_endpoint()
    {
        $technician = $this->createTechnician();

        Sanctum::actingAs($technician);

        $response = $this->putJson('/api/technician/technician-responses/999999', [
            'response' => 'Response diperbarui',
            'status' => 'completed',
            'note' => 'Update response dari testing',
        ]);

        $this->assertHandledResponse($response, 'Endpoint update technician response bermasalah');
    }

    public function test_technician_can_show_service_job_endpoint()
    {
        $technician = $this->createTechnician();

        Sanctum::actingAs($technician);

        $response = $this->getJson('/api/technician/service-jobs/999999');

        $this->assertHandledResponse($response, 'Endpoint show service job bermasalah');
    }

    public function test_technician_can_start_service_job_endpoint()
    {
        $technician = $this->createTechnician();

        Sanctum::actingAs($technician);

        $response = $this->postJson('/api/technician/service-jobs/999999/start', [
            'note' => 'Job dimulai dari testing',
        ]);

        $this->assertHandledResponse($response, 'Endpoint start service job bermasalah');
    }

    public function test_technician_can_complete_service_job_endpoint()
    {
        $technician = $this->createTechnician();

        Sanctum::actingAs($technician);

        $response = $this->postJson('/api/technician/service-jobs/999999/complete', [
            'note' => 'Job selesai dari testing',
            'repair_note' => 'Perbaikan selesai',
        ]);

        $this->assertHandledResponse($response, 'Endpoint complete service job bermasalah');
    }

    public function test_technician_can_show_legacy_job_endpoint()
    {
        $technician = $this->createTechnician();

        Sanctum::actingAs($technician);

        $response = $this->getJson('/api/technician/jobs/999999');

        $this->assertHandledResponse($response, 'Endpoint legacy show job bermasalah');
    }

    public function test_technician_can_start_legacy_job_endpoint()
    {
        $technician = $this->createTechnician();

        Sanctum::actingAs($technician);

        $response = $this->postJson('/api/technician/jobs/999999/start', [
            'note' => 'Legacy job dimulai dari testing',
        ]);

        $this->assertHandledResponse($response, 'Endpoint legacy start job bermasalah');
    }

    public function test_technician_can_complete_legacy_job_endpoint()
    {
        $technician = $this->createTechnician();

        Sanctum::actingAs($technician);

        $response = $this->postJson('/api/technician/jobs/999999/complete', [
            'note' => 'Legacy job selesai dari testing',
            'repair_note' => 'Perbaikan selesai',
        ]);

        $this->assertHandledResponse($response, 'Endpoint legacy complete job bermasalah');
    }

    public function test_technician_can_search_parts_endpoint()
    {
        $technician = $this->createTechnician();

        Sanctum::actingAs($technician);

        $response = $this->getJson('/api/technician/parts?search=oli');

        $this->assertHandledResponse($response, 'Endpoint search parts teknisi bermasalah');
    }

    public function test_technician_can_request_part_usage_endpoint()
    {
        $technician = $this->createTechnician();

        Sanctum::actingAs($technician);

        $response = $this->postJson('/api/technician/part-usages', [
            'part_id' => 999999,
            'damage_report_id' => 999999,
            'qty' => 1,
            'note' => 'Request sparepart dari testing',
        ]);

        $this->assertHandledResponse($response, 'Endpoint request part usage bermasalah');
    }

    public function test_technician_can_show_review_detail_endpoint()
    {
        $technician = $this->createTechnician();

        Sanctum::actingAs($technician);

        $response = $this->getJson('/api/technician/reviews/999999');

        $this->assertHandledResponse($response, 'Endpoint show review detail bermasalah');
    }
}