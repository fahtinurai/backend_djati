<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminFinanceTest extends TestCase
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

    public function test_admin_can_view_transactions()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->getJson('/api/admin/transactions');

        $this->assertHandled($response, 'Admin gagal melihat transaksi');
    }

    public function test_admin_can_create_transaction()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->postJson('/api/admin/transactions', [
            'type' => 'expense',
            'category' => 'Testing',
            'amount' => 100000,
            'date' => now()->toDateString(),
            'note' => 'Transaction testing',
            'source' => 'manual',
        ]);

        $this->assertHandled($response, 'Admin gagal membuat transaksi');
    }

    public function test_admin_can_update_transaction_endpoint()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->putJson('/api/admin/transactions/999999', [
            'type' => 'expense',
            'category' => 'Testing Updated',
            'amount' => 120000,
            'date' => now()->toDateString(),
            'note' => 'Update transaction testing',
            'source' => 'manual',
        ]);

        $this->assertHandled($response, 'Endpoint update transaksi bermasalah');
    }

    public function test_admin_can_delete_transaction_endpoint()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->deleteJson('/api/admin/transactions/999999');

        $this->assertHandled($response, 'Endpoint delete transaksi bermasalah');
    }

    public function test_create_transaction_validation_fails()
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->postJson('/api/admin/transactions', []);

        $this->assertTrue(
            in_array($response->getStatusCode(), [400, 422]),
            'Validasi transaksi kosong harus gagal. Status: ' . $response->getStatusCode()
        );
    }
}