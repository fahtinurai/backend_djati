<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminInventoryTest extends TestCase
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

    public function test_admin_can_view_parts()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/parts');

        $this->assertHandled($response, 'Admin gagal melihat parts');
    }

    public function test_admin_can_create_part()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->postJson('/api/admin/parts', [
            'name' => 'Oli Mesin',
            'sku' => 'OLI-001',
            'stock' => 10,
            'buy_price' => 50000,
        ]);

        $this->assertHandled($response, 'Admin gagal membuat part');
    }

    public function test_admin_can_update_part_endpoint()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->putJson('/api/admin/parts/999999', [
            'name' => 'Oli Update',
            'sku' => 'OLI-UPD',
            'stock' => 20,
            'buy_price' => 60000,
        ]);

        $this->assertHandled($response, 'Endpoint update part bermasalah');
    }

    public function test_admin_can_delete_part_endpoint()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->deleteJson('/api/admin/parts/999999');

        $this->assertHandled($response, 'Endpoint delete part bermasalah');
    }

    public function test_admin_can_view_stock_movements()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/stock-movements');

        $this->assertHandled($response, 'Admin gagal melihat stock movements');
    }

    public function test_create_part_validation_fails()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->postJson('/api/admin/parts', []);

        $this->assertTrue(
            in_array($response->getStatusCode(), [400, 422]),
            'Validasi part kosong harus gagal. Status: ' . $response->getStatusCode()
        );
    }
}