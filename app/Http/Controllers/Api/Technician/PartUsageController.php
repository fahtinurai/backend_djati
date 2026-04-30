<?php

namespace App\Http\Controllers\Api\Technician;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use App\Models\TechnicianPartUsage;
use Illuminate\Http\Request;

// realtime publisher ke Node (tanpa ubah logic utama)
use App\Services\NodeEventPublisher;

class PartUsageController extends Controller
{
    /**
     * Teknisi request sparepart (BELUM mengurangi stok)
     * POST /api/technician/part-usages
     */
    public function store(Request $request)
    {
        $technician = $request->user();

        $data = $request->validate([
            'part_id'          => 'required|exists:parts,id',
            'damage_report_id' => 'required|exists:damage_reports,id',
            'qty'              => 'required|integer|min:1',
            'note'             => 'nullable|string',
        ]);

        // pastikan damage report valid
        $damageReport = DamageReport::findOrFail($data['damage_report_id']);

        // simpan request sparepart (status default: requested)
        $usage = TechnicianPartUsage::create([
            'technician_id'    => $technician->id,
            'part_id'          => $data['part_id'],
            'damage_report_id' => $data['damage_report_id'],
            'qty'              => $data['qty'],
            'status'           => 'requested',
            'note'             => $data['note'] ?? null,
        ]);

        // load relasi untuk response & event realtime
        $usage->load([
            'technician:id,username,role',
            'part:id,name,sku,stock',
            'damageReport:id,vehicle_id,driver_id,description,created_at',
            'damageReport.vehicle:id,plate_number,brand,model',
        ]);

        // publish event realtime untuk WEB ADMIN
        NodeEventPublisher::publish(
            'part_usage.requested',
            [
                'part_usage_id'    => $usage->id,
                'status'           => $usage->status, // requested
                'qty'              => (int) $usage->qty,
                'technician_id'    => (int) $usage->technician_id,
                'part_id'          => (int) $usage->part_id,
                'damage_report_id' => (int) $usage->damage_report_id,
                'note'             => $usage->note,
                'created_at'       => $usage->created_at,
            ],
            ['admin']
        );

        return response()->json([
            'message' => 'Request sparepart berhasil dikirim.',
            'usage'   => $usage,
        ], 201);
    }

    /**
     * Riwayat request/pengambilan sparepart teknisi ini
     * GET /api/technician/my-part-usages
     */
    public function myUsages(Request $request)
    {
        $technicianId = $request->user()->id;

        $rows = TechnicianPartUsage::with([
                'part:id,name,sku',
                'damageReport:id,vehicle_id,description,created_at',
                'damageReport.vehicle:id,plate_number,brand,model',
            ])
            ->where('technician_id', $technicianId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($rows);
    }
}
