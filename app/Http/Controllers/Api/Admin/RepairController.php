<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinanceTransaction;
use App\Models\Repair;
use App\Models\RepairPart;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\NodeEventPublisher;

//nawra menambahkan RepairController.php
class RepairController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $rows = Repair::with(['damageReport.vehicle', 'technician', 'parts.part'])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('vehicle_plate', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%")
                        ->orWhereHas('damageReport.vehicle', fn ($v) => $v->where('plate_number', 'like', "%{$search}%"))
                        ->orWhereHas('technician', fn ($t) => $t->where('username', 'like', "%{$search}%"));
                });
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (Repair $r) => $this->shape($r));

        return response()->json($rows);
    }

    public function show(Repair $repair)
    {
        $repair->load(['damageReport.vehicle', 'technician', 'parts.part']);
        return response()->json($this->shape($repair));
    }

    public function finalize(Request $request, Repair $repair)
    {
        $data = $request->validate([
            'technician_id'        => 'nullable',
            'technician'           => 'nullable|string',
            'action'               => 'required|string',
            'cost'                 => 'nullable|numeric|min:0',
            'parts_used'           => 'array',
            'parts_used.*.part_id' => 'required|exists:parts,id',
            'parts_used.*.qty'     => 'required|integer|min:1',
        ]);

        if ((bool) $repair->finalized) {
            return response()->json(['message' => 'Repair ini sudah difinalisasi.'], 422);
        }

        $repair->load(['damageReport.vehicle']);
        $dr = $repair->damageReport;

        if (!$dr) {
            return response()->json(['message' => 'Damage report tidak ditemukan.'], 422);
        }

        // ✅ KUNCI: cukup cek damage_reports.status
        if (($dr->status ?? '') !== 'approved_followup_admin') {
            return response()->json([
                'message' => 'Repair hanya bisa difinalisasi setelah follow-up disetujui admin.',
                'debug'   => [
                    'damage_report_status' => $dr->status ?? null,
                ],
            ], 422);
        }

        // resolve technician id (id / username)
        $technicianId = null;
        if (!empty($data['technician_id'])) {
            $technicianId = (int) $data['technician_id'];
        } else {
            $tech = trim((string) ($data['technician'] ?? ''));
            if ($tech !== '') {
                $technicianId = ctype_digit($tech)
                    ? (int) $tech
                    : User::where('username', $tech)->value('id');
            }
        }

        if (!$technicianId || !User::whereKey($technicianId)->exists()) {
            return response()->json(['message' => 'Teknisi tidak valid.'], 422);
        }

        try {
            DB::transaction(function () use ($repair, $data, $dr, $technicianId) {

                // pastikan plate tidak null (kolom wajib)
                $plate = $repair->vehicle_plate
                    ?: optional($dr->vehicle)->plate_number
                    ?: 'UNKNOWN';

                // 1) update repair
                $repair->update([
                    'technician_id' => $technicianId,
                    'action'        => $data['action'],
                    'cost'          => $data['cost'] ?? 0,
                    'finalized'     => true,
                    'finalized_at'  => now(),
                    'repair_date'   => $repair->repair_date ? $repair->repair_date->toDateString() : now()->toDateString(),
                    'vehicle_plate' => $plate,
                ]);

                /**
                 * 2) parts usage (HISTORY ONLY)
                 * - Simpan ke RepairPart untuk riwayat pemakaian
                 * - TIDAK mengurangi Part.stock
                 * - TIDAK membuat StockMovement OUT
                 *
                 * Agar tidak dobel ketika ada retry / refresh,
                 * kita bersihkan dulu data parts untuk repair ini.
                 */
                RepairPart::where('repair_id', $repair->id)->delete();

                foreach (($data['parts_used'] ?? []) as $p) {
                    $pid = (int) $p['part_id'];
                    $qty = (int) $p['qty'];

                    RepairPart::create([
                        'repair_id' => $repair->id,
                        'part_id'   => $pid,
                        'qty'       => $qty,
                    ]);
                }

                // 3) finance
                $cost = (float) ($data['cost'] ?? 0);
                if ($cost > 0) {
                    FinanceTransaction::create([
                        'type'     => 'expense',
                        'category' => 'Repair',
                        'amount'   => $cost,
                        'date'     => now()->toDateString(),
                        'note'     => 'Repair #' . $repair->id,
                        'source'   => 'repair',
                        'ref'      => $repair->id,
                        'locked'   => true,
                    ]);
                }

                // 4) set damage report selesai
                $dr->update([
                    'status' => 'selesai',
                ]);
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        $repair->refresh();

        try {
            NodeEventPublisher::publish('repair.finalized', [
                'repair_id'        => $repair->id,
                'damage_report_id' => $repair->damage_report_id,
                'finalized'        => true,
                'vehicle_plate'    => $repair->vehicle_plate,
                'technician_id'    => $repair->technician_id,
                'action'           => $repair->action,
                'cost'             => $repair->cost,
                'repair_date'      => optional($repair->repair_date)?->toDateString(),
                'finalized_at'     => $repair->finalized_at,
            ], ['admin']);
        } catch (\Throwable $e) {
            \Log::error('NodeEventPublisher error (repair.finalized): ' . $e->getMessage());
        }

        return response()->json(['message' => 'Repair berhasil difinalisasi']);
    }

    private function shape(Repair $r): array
    {
        $plate = $r->vehicle_plate ?: optional(optional($r->damageReport)->vehicle)->plate_number ?: '-';
        $date  = $r->repair_date ? $r->repair_date->toDateString() : optional($r->created_at)->toDateString();

        return [
            'id'               => $r->id,
            'date'             => $date,
            'vehiclePlate'     => $plate,
            'finalized'        => (bool) $r->finalized,
            'technician'       => optional($r->technician)->username ?? '',
            'technician_id'    => $r->technician_id,
            'action'           => $r->action ?? '',
            'cost'             => (float) $r->cost,
            'partsUsed'        => $r->parts->map(fn ($rp) => [
                'partId' => $rp->part_id,
                'qty'    => $rp->qty,
                'sku'    => optional($rp->part)->sku,
                'name'   => optional($rp->part)->name,
            ])->values(),
            'damage_report_id' => $r->damage_report_id,
            'finalized_at'     => optional($r->finalized_at)?->toISOString(),
        ];
    }
}
