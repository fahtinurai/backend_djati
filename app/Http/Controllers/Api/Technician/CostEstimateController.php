<?php

namespace App\Http\Controllers\Api\Technician;

use App\Http\Controllers\Controller;
use App\Models\CostEstimate;
use App\Models\DamageReport;
use Illuminate\Http\Request;
use App\Services\FcmService;
use App\Services\NodeEventPublisher;

class CostEstimateController extends Controller
{
    /**
     * Buat / overwrite draft estimasi untuk suatu DamageReport
     */
    public function store(Request $request, DamageReport $damageReport)
    {
        $tech = $request->user();

        $request->validate([
            'labor_cost' => 'required|integer|min:0',
            'parts_cost' => 'required|integer|min:0',
            'other_cost' => 'nullable|integer|min:0',
            'note'       => 'nullable|string',
        ]);

        // (opsional tapi recommended) guard: pastikan report "pernah ditangani" teknisi ini
        // biar teknisi random tidak bisa bikin estimasi untuk report orang lain
        $damageReport->load('latestTechnicianResponse');
        $latest = $damageReport->latestTechnicianResponse;
        if ($latest && (int)$latest->technician_id !== (int)$tech->id) {
            return response()->json(['message' => 'Forbidden (bukan laporan kamu).'], 403);
        }

        $estimate = CostEstimate::updateOrCreate(
            ['damage_report_id' => $damageReport->id],
            [
                'technician_id' => $tech->id,
                'labor_cost'    => (int) $request->labor_cost,
                'parts_cost'    => (int) $request->parts_cost,
                'other_cost'    => (int) ($request->other_cost ?? 0),
                'note'          => $request->note,
                'status'        => 'draft',
            ]
        );

        // hitung total
        if (method_exists($estimate, 'recomputeTotal')) {
            $estimate->recomputeTotal();
        } else {
            $estimate->total_cost = (int)$estimate->labor_cost + (int)$estimate->parts_cost + (int)$estimate->other_cost;
        }
        $estimate->save();

        $estimate->load(['damageReport.vehicle', 'technician']);

        return response()->json([
            'message' => 'Estimasi biaya tersimpan (draft)',
            'data'    => $estimate,
        ], 201);
    }

    /**
     * Update draft/submitted (selama belum approved)
     */
    public function update(Request $request, CostEstimate $costEstimate)
    {
        $tech = $request->user();

        if ((int) $costEstimate->technician_id !== (int) $tech->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // kalau sudah approved/rejected, teknisi tidak boleh ubah
        if (in_array($costEstimate->status, ['approved','rejected'], true)) {
            return response()->json(['message' => 'Estimasi sudah diputuskan admin.'], 422);
        }

        $request->validate([
            'labor_cost' => 'sometimes|integer|min:0',
            'parts_cost' => 'sometimes|integer|min:0',
            'other_cost' => 'nullable|integer|min:0',
            'note'       => 'nullable|string',

            // teknisi hanya boleh draft/submitted
            'status'     => 'nullable|in:draft,submitted',
        ]);

        $costEstimate->fill($request->only([
            'labor_cost','parts_cost','other_cost','note','status'
        ]));

        if (method_exists($costEstimate, 'recomputeTotal')) {
            $costEstimate->recomputeTotal();
        } else {
            $costEstimate->total_cost = (int)$costEstimate->labor_cost + (int)$costEstimate->parts_cost + (int)$costEstimate->other_cost;
        }
        $costEstimate->save();

        $costEstimate->load(['damageReport.vehicle', 'technician']);

        return response()->json([
            'message' => 'Estimasi diupdate',
            'data'    => $costEstimate,
        ]);
    }

    /**
     * Submit ke admin (untuk approval)
     */
    public function submit(Request $request, CostEstimate $costEstimate, FcmService $fcm)
    {
        $tech = $request->user();

        if ((int) $costEstimate->technician_id !== (int) $tech->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (in_array($costEstimate->status, ['approved','rejected'], true)) {
            return response()->json(['message' => 'Estimasi sudah diputuskan admin.'], 422);
        }

        // pastikan total konsisten sebelum submit
        if (method_exists($costEstimate, 'recomputeTotal')) {
            $costEstimate->recomputeTotal();
        } else {
            $costEstimate->total_cost = (int)$costEstimate->labor_cost + (int)$costEstimate->parts_cost + (int)$costEstimate->other_cost;
        }

        $costEstimate->status = 'submitted';
        $costEstimate->save();

        $costEstimate->load(['damageReport.vehicle', 'damageReport.driver', 'technician']);

        // =========================
        // FCM ke ADMIN (ada estimasi masuk)
        // =========================
        try {
            $plate = $costEstimate->damageReport->vehicle->plate_number ?? '-';

            $fcm->sendToRole(
                'admin',
                'Estimasi Biaya Masuk',
                'Estimasi biaya baru untuk kendaraan ' . $plate,
                [
                    'type' => 'cost_estimate',
                    'role' => 'admin',
                    'report_id' => (string) $costEstimate->damage_report_id,
                    'estimate_id' => (string) $costEstimate->id,
                    'status' => 'submitted',
                    'total_cost' => (string) $costEstimate->total_cost,
                ]
            );
        } catch (\Throwable $e) {
            // notif jangan bikin submit gagal
        }

        // Node event (web admin realtime)
        try {
            NodeEventPublisher::publish('cost_estimate.submitted', [
                'cost_estimate_id' => $costEstimate->id,
                'damage_report_id' => $costEstimate->damage_report_id,
                'technician_id'    => $costEstimate->technician_id,
                'status'           => $costEstimate->status,
                'total_cost'       => $costEstimate->total_cost,
                'submitted_at'     => now(),
            ], ['admin']);
        } catch (\Throwable $e) {}

        return response()->json([
            'message' => 'Estimasi dikirim ke admin',
            'data'    => $costEstimate,
        ]);
    }
}
