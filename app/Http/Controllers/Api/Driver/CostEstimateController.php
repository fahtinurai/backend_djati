<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use Illuminate\Http\Request;

class CostEstimateController extends Controller
{
    /**
     * Driver hanya boleh melihat estimasi yang sudah APPROVED
     */
    public function show(Request $request, DamageReport $damageReport)
    {
        $driver = $request->user();

        // pastikan report milik driver
        if ((int) $damageReport->driver_id !== (int) $driver->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // load estimasi + teknisi
        $damageReport->load([
            'costEstimate.technician',
        ]);

        $estimate = $damageReport->costEstimate;

        // belum ada estimasi
        if (!$estimate) {
            return response()->json([
                'message' => 'Estimasi biaya belum tersedia.',
                'data' => null,
            ], 404);
        }

        // driver hanya boleh lihat kalau approved
        if ($estimate->status !== 'approved') {
            return response()->json([
                'message' => 'Estimasi biaya belum disetujui admin.',
                'data' => null,
            ], 403);
        }

        return response()->json($estimate);
    }
}
