<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\TechnicianPartUsage;
use App\Models\FinanceTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\NodeEventPublisher;
use Carbon\Carbon;

class PartUsageApprovalController extends Controller
{
    /**
     * Admin list request sparepart.
     *
     * GET /api/admin/part-usages?status=pending|requested|approved|rejected&limit=xx
     *
     * Catatan:
     * - Frontend boleh pakai pending
     * - Database flow baru memakai requested
     * - Untuk aman, pending dan requested tetap dibaca sebagai request aktif
     */
    public function index(Request $request)
    {
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 200));

        $status = $request->query('status');

        $q = TechnicianPartUsage::with([
                'technician:id,username,role',
                'part:id,name,sku,stock,buy_price',
                'damageReport:id,vehicle_id,driver_id,description,created_at',
                'damageReport.vehicle:id,plate_number,brand,model,equipment_name,serial_number',
            ])
            ->orderBy('created_at', 'desc');

        if ($status) {
            $status = strtolower(trim((string) $status));

            if (in_array($status, ['pending', 'requested'], true)) {
                $q->whereIn('status', ['pending', 'requested']);
            } elseif (in_array($status, ['approved', 'rejected'], true)) {
                $q->where('status', $status);
            } else {
                $q->where('status', $status);
            }
        }

        return response()->json(
            $q->limit($limit)->get()
        );
    }

    /**
     * Admin list pending/requested sparepart usage.
     *
     * GET /api/admin/part-usages/pending
     */
    public function pending(Request $request)
    {
        $request->merge([
            'status' => 'pending',
        ]);

        return $this->index($request);
    }

    /**
     * Admin approve request sparepart.
     *
     * POST /api/admin/part-usages/{partUsage}/approve
     *
     * Logika:
     * - Request dari teknisi status awal: requested / pending
     * - Admin approve
     * - Stok sparepart berkurang
     * - Stock movement OUT tercatat
     * - Finance expense otomatis tercatat
     * - Event dikirim ke admin dan teknisi
     */
    public function approve(Request $request, TechnicianPartUsage $partUsage)
    {
        $data = $request->validate([
            'admin_note' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($partUsage, $data) {
            /*
            |--------------------------------------------------------------------------
            | LOCK REQUEST AGAR TIDAK DIPROSES DOBEL
            |--------------------------------------------------------------------------
            */
            $partUsage = TechnicianPartUsage::lockForUpdate()
                ->findOrFail($partUsage->id);

            if (!in_array($partUsage->status, ['requested', 'pending'], true)) {
                return response()->json([
                    'message' => 'Request sudah diproses',
                ], 400);
            }

            /*
            |--------------------------------------------------------------------------
            | LOCK PART AGAR STOK AMAN
            |--------------------------------------------------------------------------
            */
            /** @var Part $part */
            $part = Part::lockForUpdate()->findOrFail($partUsage->part_id);
            $qty = (int) $partUsage->qty;

            /*
            |--------------------------------------------------------------------------
            | TANGGAL APPROVAL BERDASARKAN TIMEZONE INDONESIA
            |--------------------------------------------------------------------------
            */
            $approvalDate = Carbon::now('Asia/Jakarta')->toDateString();

            if ($qty < 1) {
                return response()->json([
                    'message' => 'Qty tidak valid',
                ], 400);
            }

            if ($part->stock < $qty) {
                return response()->json([
                    'message' => 'Stok tidak mencukupi',
                ], 400);
            }

            /*
            |--------------------------------------------------------------------------
            | VALIDASI HARGA BELI SPAREPART
            |--------------------------------------------------------------------------
            |
            | Expense finance dibuat dari harga beli sparepart.
            | Jika harga belum diisi, admin harus melengkapi dulu.
            |
            */
            $unitPrice = (float) ($part->buy_price ?? 0);

            if ($unitPrice <= 0) {
                return response()->json([
                    'message' => 'Harga beli sparepart belum diisi. Isi harga beli dulu agar pengeluaran otomatis masuk ke finance.',
                ], 422);
            }

            $totalCost = $unitPrice * $qty;

            /*
            |--------------------------------------------------------------------------
            | 1. KURANGI STOK
            |--------------------------------------------------------------------------
            */
            $part->stock -= $qty;
            $part->save();

            /*
            |--------------------------------------------------------------------------
            | 2. STOCK MOVEMENT OUT
            |--------------------------------------------------------------------------
            */
            $movement = StockMovement::create([
                'part_id' => $part->id,
                'type'    => 'OUT',
                'qty'     => $qty,
                'date'    => $approvalDate,
                'note'    => $data['admin_note']
                    ?? 'Pemakaian teknisi ID: ' . $partUsage->technician_id,
                'ref'     => 'damage_report:' . $partUsage->damage_report_id,
            ]);

            /*
            |--------------------------------------------------------------------------
            | 3. FINANCE EXPENSE INVENTORY
            |--------------------------------------------------------------------------
            */
            $financeTransaction = FinanceTransaction::updateOrCreate(
                [
                    'source' => 'inventory',
                    'ref'    => (string) $partUsage->id,
                ],
                [
                    'type'     => 'expense',
                    'category' => 'Inventory',
                    'amount'   => $totalCost,
                    'date'     => $approvalDate,
                    'note'     => 'Pengeluaran sparepart (request teknisi #' . $partUsage->id . ')',
                    'locked'   => true,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | 4. UPDATE STATUS REQUEST SPAREPART
            |--------------------------------------------------------------------------
            */
            $partUsage->status = 'approved';

            if (!empty($data['admin_note'])) {
                $partUsage->note = $this->appendAdminNote(
                    $partUsage->note,
                    'ADMIN',
                    $data['admin_note']
                );
            }

            $partUsage->save();

            /*
            |--------------------------------------------------------------------------
            | LOAD RELASI UNTUK RESPONSE DAN EVENT
            |--------------------------------------------------------------------------
            */
            $partUsage->load([
                'technician:id,username,role',
                'part:id,name,sku,stock,buy_price',
                'damageReport:id,vehicle_id,driver_id,description,created_at',
                'damageReport.vehicle:id,plate_number,brand,model,equipment_name,serial_number',
            ]);

            $eventPayload = $this->buildPartUsageEventPayload($partUsage, [
                'status' => 'approved',
                'movement_id' => $movement->id,
                'finance_transaction_id' => $financeTransaction->id,
                'expense' => $totalCost,
                'date' => $approvalDate,
                'stock_after' => $part->stock,
            ]);

            /*
            |--------------------------------------------------------------------------
            | 5. REALTIME EVENT SETELAH DATABASE COMMIT
            |--------------------------------------------------------------------------
            |
            | part_usage.approved dikirim ke admin dan teknisi.
            | inventory.expense.created cukup untuk admin.
            |
            */
            DB::afterCommit(function () use (
                $eventPayload,
                $financeTransaction,
                $partUsage,
                $totalCost,
                $qty,
                $part,
                $approvalDate
            ) {
                $this->publishNodeEvent(
                    'part_usage.approved',
                    $eventPayload,
                    ['admin', 'technician']
                );

                $this->publishNodeEvent(
                    'inventory.expense.created',
                    [
                        'part_usage_id'          => (int) $partUsage->id,
                        'finance_transaction_id' => (int) $financeTransaction->id,
                        'amount'                 => $totalCost,
                        'qty'                    => $qty,
                        'part_id'                => (int) $part->id,
                        'date'                   => $approvalDate,
                    ],
                    ['admin']
                );

                $this->notifyTechnicianViaFcm(
                    $partUsage,
                    'Request Sparepart Disetujui',
                    'Permintaan sparepart ' . ($partUsage->part?->name ?? '-') . ' telah disetujui admin.',
                    'approved'
                );
            });

            return response()->json([
                'message'             => 'Approved. Stok & expense tercatat.',
                'usage'               => $partUsage,
                'part'                => $part,
                'movement'            => $movement,
                'expense'             => $totalCost,
                'finance_transaction' => $financeTransaction,
            ]);
        });
    }

    /**
     * Admin reject request sparepart.
     *
     * POST /api/admin/part-usages/{partUsage}/reject
     *
     * Logika:
     * - Admin menolak request sparepart
     * - Status menjadi rejected
     * - Alasan penolakan masuk ke note
     * - Event dikirim ke admin dan teknisi
     * - Teknisi dapat membaca status rejected dari:
     *   damage_report.part_usages
     */
    public function reject(Request $request, TechnicianPartUsage $partUsage)
    {
        $data = $request->validate([
            'reason' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($partUsage, $data) {
            /*
            |--------------------------------------------------------------------------
            | LOCK REQUEST AGAR TIDAK DIPROSES DOBEL
            |--------------------------------------------------------------------------
            */
            $partUsage = TechnicianPartUsage::lockForUpdate()
                ->findOrFail($partUsage->id);

            if (!in_array($partUsage->status, ['requested', 'pending'], true)) {
                return response()->json([
                    'message' => 'Request sudah diproses',
                ], 400);
            }

            /*
            |--------------------------------------------------------------------------
            | UPDATE STATUS MENJADI REJECTED
            |--------------------------------------------------------------------------
            */
            $partUsage->status = 'rejected';

            if (!empty($data['reason'])) {
                $partUsage->note = $this->appendAdminNote(
                    $partUsage->note,
                    'ADMIN-REJECT',
                    $data['reason']
                );
            }

            $partUsage->save();

            /*
            |--------------------------------------------------------------------------
            | LOAD RELASI UNTUK RESPONSE DAN EVENT
            |--------------------------------------------------------------------------
            */
            $partUsage->load([
                'technician:id,username,role',
                'part:id,name,sku,stock,buy_price',
                'damageReport:id,vehicle_id,driver_id,description,created_at',
                'damageReport.vehicle:id,plate_number,brand,model,equipment_name,serial_number',
            ]);

            $eventPayload = $this->buildPartUsageEventPayload($partUsage, [
                'status' => 'rejected',
                'reason' => $data['reason'] ?? null,
                'rejected_at' => optional($partUsage->updated_at)->toISOString(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | REALTIME EVENT SETELAH DATABASE COMMIT
            |--------------------------------------------------------------------------
            |
            | Event dikirim ke admin dan teknisi.
            | Dengan ini UI teknisi bisa tahu request sparepart ditolak.
            |
            */
            DB::afterCommit(function () use ($eventPayload, $partUsage, $data) {
                $this->publishNodeEvent(
                    'part_usage.rejected',
                    $eventPayload,
                    ['admin', 'technician']
                );

                $this->notifyTechnicianViaFcm(
                    $partUsage,
                    'Request Sparepart Ditolak',
                    'Permintaan sparepart ' . ($partUsage->part?->name ?? '-') . ' ditolak admin.',
                    'rejected',
                    $data['reason'] ?? null
                );
            });

            return response()->json([
                'message' => 'Request ditolak.',
                'usage'   => $partUsage,
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER: APPEND ADMIN NOTE
    |--------------------------------------------------------------------------
    |
    | Format note:
    | [ADMIN] catatan approve
    | [ADMIN-REJECT] alasan reject
    |
    */
    private function appendAdminNote(?string $oldNote, string $tag, ?string $newNote): string
    {
        $old = trim((string) $oldNote);
        $new = trim((string) $newNote);

        if ($new === '') {
            return $old;
        }

        $line = '[' . $tag . '] ' . $new;

        return $old
            ? $old . "\n" . $line
            : $line;
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER: BUILD EVENT PAYLOAD
    |--------------------------------------------------------------------------
    |
    | Payload dibuat lengkap supaya frontend/mobile bisa membaca progress
    | tanpa perlu menebak struktur data.
    |
    */
    private function buildPartUsageEventPayload(TechnicianPartUsage $partUsage, array $extra = []): array
    {
        $part = $partUsage->part;
        $technician = $partUsage->technician;
        $damageReport = $partUsage->damageReport;
        $vehicle = $damageReport?->vehicle;

        return array_merge([
            'part_usage_id'    => (int) $partUsage->id,
            'status'           => (string) $partUsage->status,
            'qty'              => (int) $partUsage->qty,
            'note'             => $partUsage->note,

            'technician_id'    => $partUsage->technician_id ? (int) $partUsage->technician_id : null,
            'damage_report_id' => $partUsage->damage_report_id ? (int) $partUsage->damage_report_id : null,
            'part_id'          => $partUsage->part_id ? (int) $partUsage->part_id : null,

            'part_name'        => $part?->name,
            'part_sku'         => $part?->sku,
            'part_stock'       => $part?->stock,

            'technician' => $technician ? [
                'id'       => (int) $technician->id,
                'username' => $technician->username,
                'role'     => $technician->role,
            ] : null,

            'part' => $part ? [
                'id'    => (int) $part->id,
                'name'  => $part->name,
                'sku'   => $part->sku,
                'stock' => $part->stock,
            ] : null,

            'damage_report' => $damageReport ? [
                'id'          => (int) $damageReport->id,
                'description' => $damageReport->description,
                'created_at'  => optional($damageReport->created_at)->toISOString(),
            ] : null,

            'vehicle' => $vehicle ? [
                'id'             => (int) $vehicle->id,
                'plate_number'   => $vehicle->plate_number,
                'brand'          => $vehicle->brand,
                'model'          => $vehicle->model,
                'equipment_name' => $vehicle->equipment_name ?? null,
                'serial_number'  => $vehicle->serial_number ?? null,
            ] : null,

            'created_at' => optional($partUsage->created_at)->toISOString(),
            'updated_at' => optional($partUsage->updated_at)->toISOString(),
        ], $extra);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER: PUBLISH NODE EVENT
    |--------------------------------------------------------------------------
    */
    private function publishNodeEvent(string $event, array $payload, array $rooms): void
    {
        try {
            NodeEventPublisher::publish($event, $payload, $rooms);
        } catch (\Throwable $e) {
            Log::warning('Gagal publish node event ' . $event, [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | HELPER: FCM KE TEKNISI
    |--------------------------------------------------------------------------
    |
    | Kalau FcmService aktif, teknisi akan mendapat notifikasi mobile.
    | Kalau FCM gagal, proses approve/reject tetap tidak terganggu.
    |
    */
    private function notifyTechnicianViaFcm(
        TechnicianPartUsage $partUsage,
        string $title,
        string $body,
        string $status,
        ?string $reason = null
    ): void {
        try {
            if (!$partUsage->relationLoaded('technician')) {
                $partUsage->load('technician:id,username,role');
            }

            $technician = $partUsage->technician;

            if (!$technician) {
                return;
            }

            $fcm = app(\App\Services\FcmService::class);

            $fcm->sendToUser(
                $technician,
                $title,
                $body,
                [
                    'type' => 'part_usage',
                    'role' => 'technician',
                    'status' => $status,
                    'part_usage_id' => (string) $partUsage->id,
                    'damage_report_id' => (string) $partUsage->damage_report_id,
                    'part_id' => (string) $partUsage->part_id,
                    'qty' => (string) $partUsage->qty,
                    'reason' => (string) ($reason ?? ''),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Gagal kirim FCM part usage ke teknisi', [
                'part_usage_id' => $partUsage->id ?? null,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}