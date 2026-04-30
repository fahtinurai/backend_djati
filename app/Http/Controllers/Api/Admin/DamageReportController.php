<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use App\Models\Repair;
use App\Models\TechnicianResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\NodeEventPublisher;

class DamageReportController extends Controller
{
    /**
     * Admin melihat semua laporan kerusakan
     */
    public function index()
    {
        $reports = DamageReport::with([
                'vehicle',
                'driver',
                'technicianResponses.technician',
                'latestTechnicianResponse.technician',
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($reports);
    }

    /**
     * Admin melihat detail laporan kerusakan
     */
    public function show(DamageReport $damageReport)
    {
        $damageReport->load([
            'vehicle',
            'driver',
            'technicianResponses.technician',
            'latestTechnicianResponse.technician',
        ]);

        return response()->json($damageReport);
    }

    /**
     * Admin melihat laporan yang butuh follow-up
     */
    public function followUps()
    {
        $reports = DamageReport::with([
                'vehicle',
                'driver',
                'latestTechnicianResponse.technician',
            ])
            ->whereHas('latestTechnicianResponse', function ($q) {
                $q->where('status', 'butuh_followup_admin');
            })
            // urutkan berdasarkan response terbaru
            ->orderByDesc(
                TechnicianResponse::select('created_at')
                    ->whereColumn('technician_responses.damage_id', 'damage_reports.id') // ✅ FIX: damage_id
                    ->latest()
                    ->take(1)
            )
            ->get();

        return response()->json($reports);
    }

    /**
     * Admin approve follow-up:
     * - update damage_reports.status = approved_followup_admin
     * - buat audit trail technician_responses (status approved_followup_admin)
     * - auto create repair draft (firstOrCreate)
     */
    public function markAsCompleted(Request $request, DamageReport $damageReport)
    {
        $request->validate([
            'admin_note' => 'nullable|string',
        ]);

        $damageReport->load(['vehicle', 'latestTechnicianResponse']);

        $latest = $damageReport->latestTechnicianResponse;

        if (!$latest || $latest->status !== 'butuh_followup_admin') {
            return response()->json([
                'message' => 'Laporan ini tidak dalam status butuh follow-up admin.',
                'debug'   => [
                    'latest_status' => $latest?->status,
                    'dr_status'     => $damageReport->status ?? null,
                ],
            ], 422);
        }

        try {
            DB::transaction(function () use ($request, $damageReport) {

                // 1) Update status damage report
                $damageReport->update([
                    'status' => 'approved_followup_admin',
                ]);

                // 2) Audit trail
                $damageReport->technicianResponses()->create([
                    'damage_id'      => $damageReport->id, // ✅ FIX: damage_id
                    'technician_id'  => null,
                    'status'         => 'approved_followup_admin',
                    'note'           => $request->admin_note ?? 'Approved by admin',
                ]);

                // 3) Auto create repair draft
                Repair::firstOrCreate(
                    ['damage_report_id' => $damageReport->id],
                    [
                        'vehicle_plate' => optional($damageReport->vehicle)->plate_number ?? 'UNKNOWN', // ✅ wajib
                        'finalized'     => false,
                        'repair_date'   => now()->toDateString(), // ✅ untuk UI tanggal
                        'cost'          => 0,
                    ]
                );
            });
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }

        // ambil repair yang barusan dibuat
        $repair = Repair::where('damage_report_id', $damageReport->id)->first();

        // realtime events
        try {
            NodeEventPublisher::publish('damage_report.followup_approved', [
                'damage_report_id' => $damageReport->id,
                'status'           => 'approved_followup_admin',
                'admin_note'       => $request->admin_note,
                'updated_at'       => now(),
            ], ['admin']);

            if ($repair) {
                NodeEventPublisher::publish('repair.created', [
                    'repair_id'        => $repair->id,
                    'damage_report_id' => $damageReport->id,
                    'finalized'        => false,
                    'vehicle_plate'    => $repair->vehicle_plate,
                    'repair_date'      => optional($repair->repair_date)?->toDateString(),
                    'created_at'       => $repair->created_at,
                ], ['admin']);
            }
        } catch (\Throwable $e) {
            \Log::error('NodeEventPublisher error: ' . $e->getMessage());
        }

        $damageReport->refresh();
        $damageReport->load([
            'vehicle',
            'driver',
            'technicianResponses.technician',
            'latestTechnicianResponse.technician',
        ]);

        return response()->json([
            'message'       => 'Follow-up disetujui & repair draft dibuat',
            'damage_report' => $damageReport,
            'repair'        => $repair,
        ]);
    }
}
