<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Services\NodeEventPublisher;

class DamageReportController extends Controller
{
    /**
     * Daftar kendaraan yang di-assign ke driver login
     */
    public function myVehicles(Request $request)
    {
        $driverId = $request->user()->id;

        $assignments = VehicleAssignment::with('vehicle')
            ->where('driver_id', $driverId)
            ->orderBy('assigned_at', 'desc')
            ->get();

        return response()->json($assignments);
    }

    /**
     * Kendaraan aktif / kendaraan utama driver login.
     * Ini cocok untuk UI Damage Report yang otomatis mengambil kendaraan assigned.
     */
    public function myVehicle(Request $request)
    {
        $driverId = $request->user()->id;

        $assignment = VehicleAssignment::with('vehicle')
            ->where('driver_id', $driverId)
            ->orderBy('assigned_at', 'desc')
            ->first();

        if (!$assignment || !$assignment->vehicle) {
            return response()->json([
                'message' => 'Belum ada kendaraan yang di-assign ke akun driver ini.',
            ], 404);
        }

        return response()->json([
            'data' => $assignment,
        ]);
    }

    /**
     * Daftar laporan kerusakan milik driver login
     */
    public function index(Request $request)
    {
        $q = DamageReport::query()
            ->where('driver_id', $request->user()->id)
            ->with([
                'vehicle',
                'latestTechnicianResponse.technician',
            ])
            ->latest();

        if ($request->filled('status')) {
            $status = $request->status;

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
     * Verifikasi kendaraan berdasarkan equipment_name.
     * Tetap disediakan kalau UI lama masih memakai input manual Unit Name.
     */
    public function verifyVehicle(Request $request)
    {
        $driver = $request->user();

        $request->validate([
            'equipment_name' => 'required|string|exists:vehicles,equipment_name',
        ]);

        $vehicle = Vehicle::where('equipment_name', $request->equipment_name)
            ->firstOrFail();

        $assignment = VehicleAssignment::where('vehicle_id', $vehicle->id)
            ->where('driver_id', $driver->id)
            ->first();

        if (!$assignment) {
            return response()->json([
                'message' => 'Kendaraan ini tidak di-assign ke Anda',
            ], 403);
        }

        return response()->json([
            'message'    => 'Kendaraan terverifikasi',
            'vehicle'    => $vehicle,
            'assignment' => $assignment,
        ]);
    }

    /**
     * Tambah laporan kerusakan baru dari mobile driver.
     *
     * Versi ini disesuaikan:
     * - Backend mengambil kendaraan dari vehicle_assignments.
     * - Frontend boleh tetap mengirim equipment_name.
     * - Tapi vehicle_id tetap dipastikan dari assignment driver login.
     */
    public function store(Request $request)
    {
        $driver = $request->user();

        $request->validate([
            'equipment_name' => 'nullable|string|max:255',
            'damage_type'    => 'required|string|max:255',
            'description'    => 'required|string',
            'image'          => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        /**
         * Ambil kendaraan yang di-assign ke driver login.
         * Karena sistem kamu sekarang 1 driver = 1 kendaraan aktif,
         * maka ambil assignment terbaru.
         */
        $assignment = VehicleAssignment::with('vehicle')
            ->where('driver_id', $driver->id)
            ->orderBy('assigned_at', 'desc')
            ->first();

        if (!$assignment || !$assignment->vehicle) {
            return response()->json([
                'message' => 'Driver belum memiliki kendaraan yang di-assign.',
            ], 403);
        }

        $vehicle = $assignment->vehicle;

        /**
         * Kalau frontend masih mengirim equipment_name,
         * pastikan equipment_name itu sama dengan kendaraan yang di-assign.
         * Ini supaya driver tidak bisa manipulasi nama kendaraan.
         */
        if ($request->filled('equipment_name')) {
            $inputEquipmentName = trim($request->equipment_name);
            $assignedEquipmentName = trim($vehicle->equipment_name ?? '');

            if (
                $assignedEquipmentName !== '' &&
                strtolower($inputEquipmentName) !== strtolower($assignedEquipmentName)
            ) {
                return response()->json([
                    'message' => 'Equipment name tidak sesuai dengan kendaraan yang di-assign ke Anda.',
                ], 403);
            }
        }

        /**
         * Simpan foto laporan.
         * Path akan tersimpan ke database, contoh:
         * damage_reports/namafile.jpg
         *
         * Pastikan sudah menjalankan:
         * php artisan storage:link
         */
        $imagePath = $request->file('image')->store('damage_reports', 'public');

        /**
         * Simpan laporan.
         * driver_id dan vehicle_id berasal dari backend,
         * bukan dari input manual frontend.
         */
        $report = DamageReport::create([
            'vehicle_id'   => $vehicle->id,
            'driver_id'    => $driver->id,
            'damage_type'  => $request->damage_type,
            'description'  => $request->description,
            'image'        => $imagePath,
        ]);

        $report->load('vehicle');

        NodeEventPublisher::publish('damage_report.created', [
            'id'             => $report->id,
            'vehicle_id'     => $report->vehicle_id,
            'driver_id'      => $report->driver_id,
            'equipment_name' => $report->vehicle?->equipment_name,
            'plate_number'   => $report->vehicle?->plate_number,
            'damage_type'    => $report->damage_type,
            'description'    => $report->description,
            'image'          => $report->image,
            'created_at'     => $report->created_at,
        ], ['admin']);

        return response()->json($report, 201);
    }

    /**
     * Detail laporan kerusakan
     */
    public function show(Request $request, DamageReport $damageReport)
    {
        $driverId = $request->user()->id;

        if ((int) $damageReport->driver_id !== (int) $driverId) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        $damageReport->load([
            'vehicle',
            'technicianResponses' => fn ($q) => $q->orderBy('created_at', 'asc'),
            'technicianResponses.technician',
            'latestTechnicianResponse.technician',
        ]);

        return response()->json($damageReport);
    }

    /**
     * Update laporan kerusakan
     */
    public function update(Request $request, DamageReport $damageReport)
    {
        $driverId = $request->user()->id;

        if ((int) $damageReport->driver_id !== (int) $driverId) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        /**
         * Pastikan kendaraan laporan ini masih kendaraan yang di-assign ke driver.
         */
        $assigned = VehicleAssignment::where('vehicle_id', $damageReport->vehicle_id)
            ->where('driver_id', $driverId)
            ->exists();

        if (!$assigned) {
            return response()->json([
                'message' => 'Kendaraan pada laporan ini tidak lagi di-assign ke Anda.',
            ], 403);
        }

        $request->validate([
            'damage_type' => 'sometimes|string|max:255',
            'description' => 'sometimes|required|string',
            'image'       => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        $data = $request->only([
            'damage_type',
            'description',
        ]);

        if ($request->hasFile('image')) {
            /**
             * Hapus foto lama jika ada.
             */
            if ($damageReport->image && Storage::disk('public')->exists($damageReport->image)) {
                Storage::disk('public')->delete($damageReport->image);
            }

            $data['image'] = $request->file('image')->store('damage_reports', 'public');
        }

        $damageReport->update($data);
        $damageReport->load('vehicle');

        NodeEventPublisher::publish('damage_report.updated', [
            'id'             => $damageReport->id,
            'vehicle_id'     => $damageReport->vehicle_id,
            'driver_id'      => $damageReport->driver_id,
            'equipment_name' => $damageReport->vehicle?->equipment_name,
            'plate_number'   => $damageReport->vehicle?->plate_number,
            'damage_type'    => $damageReport->damage_type,
            'description'    => $damageReport->description,
            'image'          => $damageReport->image,
            'updated_at'     => $damageReport->updated_at,
        ], ['admin']);

        return response()->json($damageReport);
    }

    /**
     * Hapus laporan kerusakan
     */
    public function destroy(Request $request, DamageReport $damageReport)
    {
        $driverId = $request->user()->id;

        if ((int) $damageReport->driver_id !== (int) $driverId) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        $deletedId = $damageReport->id;

        if ($damageReport->image && Storage::disk('public')->exists($damageReport->image)) {
            Storage::disk('public')->delete($damageReport->image);
        }

        $damageReport->delete();

        NodeEventPublisher::publish('damage_report.deleted', [
            'id'        => $deletedId,
            'driver_id' => $driverId,
        ], ['admin']);

        return response()->json([
            'message' => 'Laporan kerusakan dihapus',
        ]);
    }
}