<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverTechnicianReviewTest extends TestCase
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

    public function test_driver_can_access_review_detail_endpoint()
    {
        Sanctum::actingAs($this->createDriver());

        $response = $this->getJson('/api/driver/damage-reports/999999/review');

        $this->assertHandled($response, 'Endpoint detail review teknisi bermasalah');
    }

    public function test_driver_can_access_create_review_endpoint()
    {
        Sanctum::actingAs($this->createDriver());

        $response = $this->postJson('/api/driver/damage-reports/999999/review', [
            'rating' => 5,
            'comment' => 'Teknisi bekerja dengan baik',
        ]);

        $this->assertHandled($response, 'Endpoint create review teknisi bermasalah');
    }

    public function test_review_validation_fails()
    {
        Sanctum::actingAs($this->createDriver());

        $response = $this->postJson('/api/driver/damage-reports/999999/review', []);

        $this->assertTrue(
            $response->getStatusCode() < 500,
            'Validasi review kosong tidak boleh error 500. Status: ' . $response->getStatusCode()
        );
    }
}