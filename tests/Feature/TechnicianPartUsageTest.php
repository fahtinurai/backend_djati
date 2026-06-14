<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TechnicianPartUsageTest extends TestCase
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

    public function test_technician_can_view_parts()
    {
        Sanctum::actingAs($this->createTechnician());

        $response = $this->getJson('/api/technician/parts');

        $this->assertHandled($response, 'Teknisi gagal melihat parts');
    }

    public function test_technician_can_search_parts()
    {
        Sanctum::actingAs($this->createTechnician());

        $response = $this->getJson('/api/technician/parts?search=oli');

        $this->assertHandled($response, 'Teknisi gagal search parts');
    }

    public function test_technician_can_view_my_part_usages()
    {
        Sanctum::actingAs($this->createTechnician());

        $response = $this->getJson('/api/technician/my-part-usages');

        $this->assertHandled($response, 'Teknisi gagal melihat my part usages');
    }

    public function test_technician_can_access_create_part_usage_endpoint()
    {
        Sanctum::actingAs($this->createTechnician());

        $response = $this->postJson('/api/technician/part-usages', [
            'part_id' => 999999,
            'damage_report_id' => 999999,
            'qty' => 1,
            'note' => 'Request sparepart testing',
        ]);

        $this->assertHandled($response, 'Endpoint create part usage teknisi bermasalah');
    }

    public function test_part_usage_validation_fails()
    {
        Sanctum::actingAs($this->createTechnician());

        $response = $this->postJson('/api/technician/part-usages', []);

        $this->assertTrue(
            $response->getStatusCode() < 500,
            'Validasi part usage kosong tidak boleh error 500. Status: ' . $response->getStatusCode()
        );
    }
}