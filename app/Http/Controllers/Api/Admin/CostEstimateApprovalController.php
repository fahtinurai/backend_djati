<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CostEstimate;
use Illuminate\Http\Request;
use App\Services\FcmService;
use App\Services\NodeEventPublisher;

class CostEstimateApprovalController extends Controller
{
    public function index(Request $request)
    {
        // default: tampilkan yang submitted (siap di-approve)
        $status = $request->query('status', 'submitted');

        $q = CostEstimate::with([
            'damageReport.vehicle',
            'damageReport.driver',
            'technician',
        ])->latest();

        // kalau status=all, tampilkan semua
        if ($status && $status !== 'all') {
            $q->where('status', $status);
        }

        return response()->json($q->get());
    }

    public function approve(Request $request, CostEstimate $costEstimate, FcmService $fcm)
    {
        if ($costEstimate->status !== 'submitted') {
            return response()->json(['message' => 'Estimasi harus berstatus submitted.'], 422);
        }

        $admin = $request->user();

        // pastikan total cost konsisten
        if (method_exists($costEstimate, 'recomputeTotal')) {
            $costEstimate->recomputeTotal();
        } else {
            $costEstimate->total_cost = (int)$costEstimate->labor_cost + (int)$costEstimate->parts_cost + (int)$costEstimate->other_cost;
        }

        $costEstimate->status = 'approved';
        $costEstimate->approved_by = $admin->id;
        $costEstimate->approved_at = now();
        $costEstimate->save();

        // load relasi untuk response + notif
        $costEstimate->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'technician',
        ]);

        // =========================
        // FCM (driver & teknisi)
        // =========================
        try {
            $report = $costEstimate->damageReport;

            // notif ke teknisi
            if ($costEstimate->technician) {
                $fcm->sendToUser(
                    $costEstimate->technician,
                    'Estimasi Biaya Di-approve',
                    'Estimasi biaya kamu sudah disetujui admin.',
                    [
                        'type' => 'cost_estimate',
                        'role' => 'technician',
                        'report_id' => (string) $report->id,
                        'estimate_id' => (string) $costEstimate->id,
                        'status' => 'approved',
                        'total_cost' => (string) $costEstimate->total_cost,
                    ]
                );
            }

            // notif ke driver (read-only)
            if ($report && $report->driver) {
                $fcm->sendToUser(
                    $report->driver,
                    'Estimasi Biaya Perbaikan',
                    'Estimasi biaya perbaikan tersedia.',
                    [
                        'type' => 'cost_estimate',
                        'role' => 'driver',
                        'report_id' => (string) $report->id,
                        'estimate_id' => (string) $costEstimate->id,
                        'status' => 'approved',
                        'total_cost' => (string) $costEstimate->total_cost,
                    ]
                );
            }
        } catch (\Throwable $e) {
            // jangan bikin approve gagal gara-gara notif
        }

        // =========================
        // Node event (web admin)
        // =========================
        try {
            NodeEventPublisher::publish('cost_estimate.approved', [
                'cost_estimate_id' => $costEstimate->id,
                'damage_report_id' => $costEstimate->damage_report_id,
                'approved_by'      => $admin->id,
                'approved_at'      => $costEstimate->approved_at,
                'total_cost'       => $costEstimate->total_cost,
            ], ['admin']);
        } catch (\Throwable $e) {
            // diam saja
        }

        return response()->json([
            'message' => 'Estimasi di-approve',
            'data'    => $costEstimate,
        ]);
    }

    public function reject(Request $request, CostEstimate $costEstimate, FcmService $fcm)
    {
        $request->validate([
            'note' => 'nullable|string',
        ]);

        if ($costEstimate->status !== 'submitted') {
            return response()->json(['message' => 'Estimasi harus berstatus submitted.'], 422);
        }

        $admin = $request->user();

        $costEstimate->status = 'rejected';
        if ($request->filled('note')) {
            $costEstimate->note = $request->note;
        }
        $costEstimate->approved_by = $admin->id; 
        $costEstimate->approved_at = now();      
        $costEstimate->save();

        $costEstimate->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'technician',
        ]);

        // notif teknisi + driver
        try {
            $report = $costEstimate->damageReport;

            if ($costEstimate->technician) {
                $fcm->sendToUser(
                    $costEstimate->technician,
                    'Estimasi Biaya Ditolak',
                    'Estimasi biaya ditolak admin. Cek catatan untuk revisi.',
                    [
                        'type' => 'cost_estimate',
                        'role' => 'technician',
                        'report_id' => (string) $report->id,
                        'estimate_id' => (string) $costEstimate->id,
                        'status' => 'rejected',
                    ]
                );
            }

            if ($report && $report->driver) {
                $fcm->sendToUser(
                    $report->driver,
                    'Estimasi Biaya Perbaikan',
                    'Estimasi biaya sedang direvisi.',
                    [
                        'type' => 'cost_estimate',
                        'role' => 'driver',
                        'report_id' => (string) $report->id,
                        'estimate_id' => (string) $costEstimate->id,
                        'status' => 'rejected',
                    ]
                );
            }
        } catch (\Throwable $e) {}

        // node event
        try {
            NodeEventPublisher::publish('cost_estimate.rejected', [
                'cost_estimate_id' => $costEstimate->id,
                'damage_report_id' => $costEstimate->damage_report_id,
                'rejected_by'      => $admin->id,
                'rejected_at'      => $costEstimate->approved_at,
            ], ['admin']);
        } catch (\Throwable $e) {}

        return response()->json([
            'message' => 'Estimasi ditolak',
            'data'    => $costEstimate,
        ]);
    }
}
