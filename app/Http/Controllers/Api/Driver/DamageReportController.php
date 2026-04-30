<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;
use App\Services\NodeEventPublisher;

class DamageReportController extends Controller
{
    /**
     * Daftar kendaraan yang di-assign ke driver ini
     */
    public function myVehicles(Request $request)
    {
        $driverId = $request->user()->id;

        $assignments = VehicleAssignment::with('vehicle')
            ->where('driver_id', $driverId)
            ->get();

        return response()->json($assignments);
    }

    /**
     * Daftar kerusakan yang dibuat driver ini
     */
    public function index(Request $request)
    {
        $q = DamageReport::query()
            ->where('driver_id', auth()->id())
            ->with([
                'vehicle',
                'latestTechnicianResponse.technician',
            ])
            ->latest();

        // Filter status DRIVER berdasarkan latestTechnicianResponse.status
        if ($request->filled('status')) {
            $status = $request->status;

            // Anggap "menunggu" = belum ada response sama sekali
            if (in_array($status, ['menunggu', 'waiting'], true)) {
                $q->whereDoesntHave('latestTechnicianResponse');
            } else {
                $q->whereHas('latestTechnicianResponse', function ($r) use ($status) {
                    $r->where('status', $status);
                });
            }
        }

        return response()->json($q->get());
    }

    /**
     * Verifikasi kendaraan berdasarkan plat nomor
     */
    public function verifyVehicle(Request $request)
    {
        $driver = $request->user();

        $request->validate([
            'plate_number' => 'required|string',
        ]);

        // Cari kendaraan berdasarkan plat
        $vehicle = \App\Models\Vehicle::where('plate_number', $request->plate_number)->first();

        if (!$vehicle) {
            return response()->json(['message' => 'Kendaraan dengan plat tersebut tidak ditemukan'], 404);
        }

        // Cek apakah kendaraan ini di-assign ke driver ini
        $assignment = VehicleAssignment::where('vehicle_id', $vehicle->id)
            ->where('driver_id', $driver->id)
            ->first();

        if (!$assignment) {
            return response()->json(['message' => 'Kendaraan ini tidak di-assign ke Anda'], 403);
        }

        return response()->json([
            'message' => 'Kendaraan terverifikasi',
            'vehicle' => $vehicle,
            'assignment' => $assignment,
        ]);
    }

    /**
     * Tambah kerusakan baru
     */
    public function store(Request $request)
    {
        $driver = $request->user();

        $request->validate([
            'plate_number' => 'required|string|exists:vehicles,plate_number',
            'description'  => 'required|string',
        ]);

        // Cari vehicle berdasarkan plat
        $vehicle = \App\Models\Vehicle::where('plate_number', $request->plate_number)->firstOrFail();

        // Cek: kendaraan ini memang di-assign ke driver ini?
        $assigned = VehicleAssignment::where('vehicle_id', $vehicle->id)
            ->where('driver_id', $driver->id)
            ->exists();

        if (!$assigned) {
            return response()->json([
                'message' => 'Kendaraan ini tidak di-assign ke Anda',
            ], 403);
        }

        $report = DamageReport::create([
            'vehicle_id'  => $vehicle->id,
            'driver_id'   => $driver->id,
            'description' => $request->description,
        ]);

        $report->load('vehicle');
        
        // publish event create ke Node realtime (untuk web admin)
        NodeEventPublisher::publish('damage_report.created', [
            'id' => $report->id,
            'vehicle_id' => $report->vehicle_id,
            'driver_id' => $report->driver_id,
            'plate_number' => $vehicle->plate_number,
            'description' => $report->description,
            'created_at' => $report->created_at,
        ], ['admin']);

        return response()->json($report, 201);
    }

    /**
     * Detail kerusakan + respon teknisi
     */
    public function show(Request $request, DamageReport $damageReport)
    {
        $driverId = $request->user()->id;

        if ($damageReport->driver_id !== $driverId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $damageReport->load([
            'vehicle',
            'technicianResponses' => fn($q) => $q->orderBy('created_at', 'asc'),
            'technicianResponses.technician',
            'latestTechnicianResponse.technician',
        ]);

        return response()->json($damageReport);
    }

    /**
     * Edit kerusakan (hanya punya sendiri)
     */
    public function update(Request $request, DamageReport $damageReport)
    {
        $driverId = $request->user()->id;

        if ($damageReport->driver_id !== $driverId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'description' => 'required|string',
        ]);

        $damageReport->update([
            'description' => $request->description,
        ]);

        // ✅ Tambahan: publish event update ke Node realtime (untuk web admin)
        NodeEventPublisher::publish('damage_report.updated', [
            'id' => $damageReport->id,
            'vehicle_id' => $damageReport->vehicle_id,
            'driver_id' => $damageReport->driver_id,
            'description' => $damageReport->description,
            'updated_at' => $damageReport->updated_at,
        ], ['admin']);

        return response()->json($damageReport);
    }

    /**
     * Hapus kerusakan (hanya punya sendiri)
     */
    public function destroy(Request $request, DamageReport $damageReport)
    {
        $driverId = $request->user()->id;

        if ($damageReport->driver_id !== $driverId) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $deletedId = $damageReport->id;
        $damageReport->delete();

        // ✅ Tambahan: publish event delete ke Node realtime (untuk web admin)
        NodeEventPublisher::publish('damage_report.deleted', [
            'id' => $deletedId,
            'driver_id' => $driverId,
        ], ['admin']);

        return response()->json(['message' => 'Laporan kerusakan dihapus']);
    }
}
