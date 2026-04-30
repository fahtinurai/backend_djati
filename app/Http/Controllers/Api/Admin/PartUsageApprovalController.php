<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Models\StockMovement;
use App\Models\TechnicianPartUsage;
use App\Models\FinanceTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\NodeEventPublisher;

class PartUsageApprovalController extends Controller
{
    /**
     * Admin list request sparepart
     * GET /api/admin/part-usages?status=pending|approved|rejected&limit=xx
     */
    public function index(Request $request)
    {
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 200));

        $status = $request->query('status');

        // mapping frontend -> database
        $map = [
            'pending'   => 'requested',
            'requested' => 'requested',
            'approved'  => 'approved',
            'rejected'  => 'rejected',
        ];

        $q = TechnicianPartUsage::with([
                'technician:id,username,role',
                'part:id,name,sku,stock,buy_price',
                'damageReport:id,vehicle_id,driver_id,description,created_at',
                'damageReport.vehicle:id,plate_number,brand,model',
            ])
            ->orderBy('created_at', 'desc');

        if ($status) {
            $q->where('status', $map[$status] ?? $status);
        }

        return response()->json(
            $q->limit($limit)->get()
        );
    }

    /**
     * Admin approve request sparepart
     * POST /api/admin/part-usages/{partUsage}/approve
     */
    public function approve(Request $request, TechnicianPartUsage $partUsage)
    {
        if ($partUsage->status !== 'requested') {
            return response()->json(['message' => 'Request sudah diproses'], 400);
        }

        $data = $request->validate([
            'admin_note' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($partUsage, $data) {

            /** @var Part $part */
            $part = Part::lockForUpdate()->findOrFail($partUsage->part_id);
            $qty  = (int) $partUsage->qty;

            if ($qty < 1) {
                return response()->json(['message' => 'Qty tidak valid'], 400);
            }

            if ($part->stock < $qty) {
                return response()->json(['message' => 'Stok tidak mencukupi'], 400);
            }

            // =============================
            // 1️⃣ KURANGI STOK
            // =============================
            $part->stock -= $qty;
            $part->save();

            // =============================
            // 2️⃣ STOCK MOVEMENT (OUT)
            // =============================
            $movement = StockMovement::create([
                'part_id' => $part->id,
                'type'    => 'OUT',
                'qty'     => $qty,
                'date'    => now()->toDateString(),
                'note'    => $data['admin_note']
                    ?? 'Pemakaian teknisi ID: ' . $partUsage->technician_id,
                'ref'     => 'damage_report:' . $partUsage->damage_report_id,
            ]);

            // =============================
            // 3️⃣ FINANCE EXPENSE (INVENTORY)
            // =============================
            $totalCost = ((float) ($part->buy_price ?? 0)) * $qty;

            if ($totalCost > 0) {
                FinanceTransaction::create([
                    'type'     => 'expense',
                    'category' => 'Inventory',
                    'amount'   => $totalCost,
                    'date'     => now(),
                    'note'     => 'Pengeluaran sparepart (request teknisi #' . $partUsage->id . ')',
                    'source'   => 'inventory',
                    'ref'      => $partUsage->id,
                    'locked'   => true,
                ]);
            }

            // =============================
            // 4️⃣ UPDATE STATUS REQUEST
            // =============================
            $partUsage->status = 'approved';

            if (!empty($data['admin_note'])) {
                $old = trim((string) $partUsage->note);
                $partUsage->note = $old
                    ? $old . "\n[ADMIN] " . trim($data['admin_note'])
                    : "[ADMIN] " . trim($data['admin_note']);
            }

            $partUsage->save();

            $partUsage->load([
                'technician:id,username,role',
                'part:id,name,sku,stock,buy_price',
                'damageReport.vehicle:id,plate_number,brand,model',
            ]);

            // =============================
            // 5️⃣ REALTIME SOCKET EVENTS
            // =============================
            NodeEventPublisher::publish('part_usage.approved', [
                'part_usage_id'   => $partUsage->id,
                'status'          => 'approved',
                'qty'             => $qty,
                'part_id'         => $part->id,
                'damage_report_id'=> $partUsage->damage_report_id,
                'stock_after'     => $part->stock,
                'movement_id'     => $movement->id,
                'expense'         => $totalCost,
            ], ['admin']);

            NodeEventPublisher::publish('inventory.expense.created', [
                'part_usage_id' => $partUsage->id,
                'amount'        => $totalCost,
                'qty'           => $qty,
                'part_id'       => $part->id,
            ], ['admin']);

            return response()->json([
                'message'  => 'Approved. Stok & expense tercatat.',
                'usage'    => $partUsage,
                'part'     => $part,
                'movement' => $movement,
                'expense'  => $totalCost,
            ]);
        });
    }

    /**
     * Admin reject request sparepart
     */
    public function reject(Request $request, TechnicianPartUsage $partUsage)
    {
        if ($partUsage->status !== 'requested') {
            return response()->json(['message' => 'Request sudah diproses'], 400);
        }

        $data = $request->validate([
            'reason' => 'nullable|string',
        ]);

        $partUsage->status = 'rejected';

        if (!empty($data['reason'])) {
            $old = trim((string) $partUsage->note);
            $partUsage->note = $old
                ? $old . "\n[ADMIN-REJECT] " . trim($data['reason'])
                : "[ADMIN-REJECT] " . trim($data['reason']);
        }

        $partUsage->save();

        NodeEventPublisher::publish('part_usage.rejected', [
            'part_usage_id' => $partUsage->id,
            'status'        => 'rejected',
            'qty'           => (int) $partUsage->qty,
            'part_id'       => $partUsage->part_id,
        ], ['admin']);

        return response()->json([
            'message' => 'Request ditolak.',
            'usage'   => $partUsage,
        ]);
    }
}
