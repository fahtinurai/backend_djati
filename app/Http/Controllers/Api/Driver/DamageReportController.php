<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Services\NodeEventPublisher;

class DamageReportController extends Controller
{
    /**
     * Daftar kendaraan yang di-assign ke driver login.
     *
     * GET /api/driver/vehicles
     *
     * PENTING:
     * HM terbaru dibaca dari relasi vehicle:
     * - vehicles.current_hour_meter
     * - vehicles.latest_hour_meter
     * - vehicles.final_hour_meter
     *
     * Jadi driver damage_report_page bagian Assigned Unit akan ikut update
     * setelah teknisi complete job dan ServiceJobController update vehicles.
     */
    public function myVehicles(Request $request)
    {
        $driverId = $request->user()->id;

        $assignments = VehicleAssignment::with('vehicle')
            ->where('driver_id', $driverId)
            ->orderBy('assigned_at', 'desc')
            ->get()
            ->map(function ($assignment) {
                return $this->assignmentResponse($assignment);
            });

        return response()->json($assignments);
    }

    /**
     * Kendaraan aktif / kendaraan utama driver login.
     *
     * GET /api/driver/my-vehicle
     *
     * Dipakai oleh DamageReportPage.dart untuk mengambil kendaraan
     * yang sedang di-assign ke driver login.
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
            'data' => $this->assignmentResponse($assignment),
        ]);
    }

    /**
     * Daftar laporan kerusakan milik driver login.
     *
     * GET /api/driver/damage-reports
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

        $reports = $q->get()->map(function ($report) {
            return $this->damageReportResponse($report);
        });

        return response()->json($reports);
    }

    /**
     * Verifikasi kendaraan berdasarkan equipment_name.
     *
     * POST /api/driver/vehicles/verify
     *
     * Tetap disediakan untuk UI lama yang masih memakai input manual Unit Name.
     * Versi ini dibuat lebih aman karena validasi tetap berdasarkan assignment driver.
     */
    public function verifyVehicle(Request $request)
    {
        $driver = $request->user();

        $request->validate([
            'equipment_name' => 'required|string|max:255',
        ], [
            'equipment_name.required' => 'Nama unit/equipment wajib diisi.',
            'equipment_name.string'   => 'Nama unit/equipment harus berupa teks.',
            'equipment_name.max'      => 'Nama unit/equipment maksimal 255 karakter.',
        ]);

        $equipmentName = trim($request->equipment_name);

        $assignment = VehicleAssignment::with('vehicle')
            ->where('driver_id', $driver->id)
            ->whereHas('vehicle', function ($query) use ($equipmentName) {
                $query->whereRaw('LOWER(equipment_name) = ?', [
                    strtolower($equipmentName),
                ]);
            })
            ->orderBy('assigned_at', 'desc')
            ->first();

        if (!$assignment || !$assignment->vehicle) {
            return response()->json([
                'message' => 'Kendaraan ini tidak di-assign ke Anda.',
            ], 403);
        }

        return response()->json([
            'message'    => 'Kendaraan terverifikasi.',
            'vehicle'    => $this->vehicleResponse($assignment->vehicle),
            'assignment' => $this->assignmentResponse($assignment),
        ]);
    }

    /**
     * Tambah laporan kerusakan baru dari mobile driver.
     *
     * POST /api/driver/damage-reports
     *
     * Flow tetap:
     * 1. Driver submit damage report.
     * 2. Backend membuat damage_reports.
     * 3. Response mengembalikan damage_report.id.
     * 4. Flutter memakai ID itu untuk request booking maintenance.
     *
     * Penyesuaian:
     * - equipment_name tetap diterima untuk kompatibilitas frontend lama.
     * - vehicle_id boleh dikirim dari Flutter.
     * - Tapi vehicle_id tetap divalidasi harus sesuai assignment driver login.
     * - Response dan realtime event membawa HM terbaru dari vehicles.current_hour_meter.
     */
    public function store(Request $request)
    {
        $driver = $request->user();

        $request->validate([
            'vehicle_id'      => 'nullable|exists:vehicles,id',
            'equipment_name'  => 'nullable|string|max:255',
            'damage_type'     => 'required|string|max:255',
            'description'     => 'required|string',
            'image'           => 'required|image|mimes:jpg,jpeg,png,webp|max:4096',
        ], [
            'vehicle_id.exists'      => 'Kendaraan tidak ditemukan.',
            'equipment_name.string'  => 'Nama unit/equipment harus berupa teks.',
            'equipment_name.max'     => 'Nama unit/equipment maksimal 255 karakter.',
            'damage_type.required'   => 'Jenis kerusakan wajib diisi.',
            'damage_type.string'     => 'Jenis kerusakan harus berupa teks.',
            'damage_type.max'        => 'Jenis kerusakan maksimal 255 karakter.',
            'description.required'   => 'Deskripsi kerusakan wajib diisi.',
            'description.string'     => 'Deskripsi kerusakan harus berupa teks.',
            'image.required'         => 'Foto bukti kerusakan wajib diunggah.',
            'image.image'            => 'File harus berupa gambar.',
            'image.mimes'            => 'Format gambar harus jpg, jpeg, png, atau webp.',
            'image.max'              => 'Ukuran gambar maksimal 4MB.',
        ]);

        $assignmentQuery = VehicleAssignment::with('vehicle')
            ->where('driver_id', $driver->id);

        if ($request->filled('vehicle_id')) {
            $assignmentQuery->where('vehicle_id', $request->vehicle_id);
        }

        $assignment = $assignmentQuery
            ->orderBy('assigned_at', 'desc')
            ->first();

        if (!$assignment || !$assignment->vehicle) {
            return response()->json([
                'message' => 'Driver belum memiliki kendaraan yang sesuai dengan assignment.',
            ], 403);
        }

        $vehicle = $assignment->vehicle;

        if ($request->filled('equipment_name')) {
            $inputEquipmentName = trim((string) $request->equipment_name);
            $assignedEquipmentName = trim((string) ($vehicle->equipment_name ?? ''));

            if (
                $assignedEquipmentName !== '' &&
                strtolower($inputEquipmentName) !== strtolower($assignedEquipmentName)
            ) {
                return response()->json([
                    'message' => 'Equipment name tidak sesuai dengan kendaraan yang di-assign ke Anda.',
                ], 403);
            }
        }

        $imagePath = $request->file('image')->store('damage_reports', 'public');

        $report = DamageReport::create([
            'vehicle_id'  => $vehicle->id,
            'driver_id'   => $driver->id,
            'damage_type' => $request->damage_type,
            'description' => $request->description,
            'image'       => $imagePath,
        ]);

        $report->load('vehicle');

        $this->publishRealtimeEvent('damage_report.created', $this->damageReportEventPayload($report), ['admin']);

        return response()->json($this->damageReportResponse($report), 201);
    }

    /**
     * Detail laporan kerusakan.
     *
     * GET /api/driver/damage-reports/{damageReport}
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

        return response()->json($this->damageReportResponse($damageReport));
    }

    /**
     * Update laporan kerusakan.
     *
     * PUT /api/driver/damage-reports/{damageReport}
     */
    public function update(Request $request, DamageReport $damageReport)
    {
        $driverId = $request->user()->id;

        if ((int) $damageReport->driver_id !== (int) $driverId) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

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
        ], [
            'damage_type.string'   => 'Jenis kerusakan harus berupa teks.',
            'damage_type.max'      => 'Jenis kerusakan maksimal 255 karakter.',
            'description.required' => 'Deskripsi kerusakan wajib diisi.',
            'description.string'   => 'Deskripsi kerusakan harus berupa teks.',
            'image.image'          => 'File harus berupa gambar.',
            'image.mimes'          => 'Format gambar harus jpg, jpeg, png, atau webp.',
            'image.max'            => 'Ukuran gambar maksimal 4MB.',
        ]);

        $data = $request->only([
            'damage_type',
            'description',
        ]);

        if ($request->hasFile('image')) {
            if (
                $damageReport->image &&
                Storage::disk('public')->exists($damageReport->image)
            ) {
                Storage::disk('public')->delete($damageReport->image);
            }

            $data['image'] = $request->file('image')
                ->store('damage_reports', 'public');
        }

        $damageReport->update($data);
        $damageReport->load('vehicle');

        $this->publishRealtimeEvent('damage_report.updated', $this->damageReportEventPayload($damageReport), ['admin']);

        return response()->json($this->damageReportResponse($damageReport));
    }

    /**
     * Hapus laporan kerusakan.
     *
     * DELETE /api/driver/damage-reports/{damageReport}
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

        if (
            $damageReport->image &&
            Storage::disk('public')->exists($damageReport->image)
        ) {
            Storage::disk('public')->delete($damageReport->image);
        }

        $damageReport->delete();

        $this->publishRealtimeEvent('damage_report.deleted', [
            'id'        => $deletedId,
            'driver_id' => $driverId,
        ], ['admin']);

        return response()->json([
            'message' => 'Laporan kerusakan dihapus.',
        ]);
    }

    /**
     * Format response assignment agar Flutter driver dapat membaca:
     * data.vehicle.initial_hour_meter
     * data.vehicle.current_hour_meter
     * data.vehicle.target_availability
     * data.vehicle.status
     */
    private function assignmentResponse(VehicleAssignment $assignment): array
    {
        return [
            'id'          => $assignment->id,
            'vehicle_id'  => $assignment->vehicle_id,
            'driver_id'   => $assignment->driver_id,
            'assigned_at' => $assignment->assigned_at,
            'created_at'  => $assignment->created_at,
            'updated_at'  => $assignment->updated_at,

            'vehicle' => $assignment->vehicle
                ? $this->vehicleResponse($assignment->vehicle)
                : null,
        ];
    }

    /**
     * Format response damage report.
     *
     * Tetap root object, supaya flow Flutter lama yang mengambil ID
     * dari response langsung tetap aman.
     */
    private function damageReportResponse(DamageReport $report): array
    {
        $vehicle = $report->vehicle;
        $vehicleResponse = $vehicle ? $this->vehicleResponse($vehicle) : null;

        return [
            'id'          => $report->id,
            'vehicle_id'  => $report->vehicle_id,
            'driver_id'   => $report->driver_id,
            'damage_type' => $report->damage_type,
            'description' => $report->description,
            'image'       => $report->image,
            'image_url'   => $this->imageUrl($report->image),
            'status'      => $report->status ?? null,
            'created_at'  => $report->created_at,
            'updated_at'  => $report->updated_at,

            /**
             * Vehicle lengkap untuk assigned unit di Flutter driver.
             */
            'vehicle' => $vehicleResponse,

            /**
             * Alias vehicle di root agar UI lama tetap aman.
             */
            'vehicle_equipment_name' => $vehicle?->equipment_name,
            'vehicle_plate_number'   => $vehicle?->plate_number,
            'vehicle_serial_number'  => $vehicle?->serial_number,
            'vehicle_initial_kpi'    => $vehicle?->initial_kpi,
            'vehicle_initial_hour_meter' => $vehicle?->initial_kpi,
            'vehicle_current_hour_meter' => $this->getVehicleCurrentHourMeter($vehicle),
            'vehicle_latest_hour_meter'  => $this->getVehicleLatestHourMeter($vehicle),
            'vehicle_final_hour_meter'   => $this->getVehicleFinalHourMeter($vehicle),
            'vehicle_hour_meter_terbaru' => $this->getVehicleCurrentHourMeter($vehicle),
            'vehicle_target_availability' => $this->getVehicleTargetAvailability($vehicle),
            'vehicle_current_ma' => $this->getVehicleCurrentMa($vehicle),
            'vehicle_status' => $this->getVehicleStatus($vehicle),

            'latest_technician_response' => $report->latestTechnicianResponse ?? null,
            'technician_responses'       => $report->technicianResponses ?? null,
        ];
    }

    /**
     * Format vehicle agar kompatibel dengan:
     * - backend lama initial_kpi
     * - frontend baru initial_hour_meter
     * - VehiclePage.jsx
     * - VehicleAssignment
     * - Flutter driver
     */
    private function vehicleResponse(?Vehicle $vehicle): ?array
    {
        if (!$vehicle) {
            return null;
        }

        $initialHourMeter = $vehicle->initial_kpi ?? 0;
        $currentHourMeter = $this->getVehicleCurrentHourMeter($vehicle);
        $latestHourMeter = $this->getVehicleLatestHourMeter($vehicle);
        $finalHourMeter = $this->getVehicleFinalHourMeter($vehicle);
        $currentMa = $this->getVehicleCurrentMa($vehicle);

        return [
            'id' => $vehicle->id,

            'equipment_name' => $vehicle->equipment_name,
            'brand'          => $vehicle->brand,
            'model'          => $vehicle->model,
            'plate_number'   => $vehicle->plate_number,
            'serial_number'  => $vehicle->serial_number,

            /**
             * Field lama database.
             */
            'initial_kpi' => $vehicle->initial_kpi,

            /**
             * Field baru untuk frontend/mobile.
             */
            'initial_hour_meter' => $initialHourMeter,

            /**
             * HM terbaru dari teknisi/admin.
             * Ini yang harus dibaca Assigned Unit driver.
             */
            'current_hour_meter' => $currentHourMeter,
            'latest_hour_meter' => $latestHourMeter,
            'final_hour_meter' => $finalHourMeter,
            'hour_meter_terbaru' => $currentHourMeter,

            /**
             * Target MA dan MA terbaru.
             */
            'target_availability' => $this->getVehicleTargetAvailability($vehicle),
            'target_ma' => $this->getVehicleTargetAvailability($vehicle),
            'current_ma' => $currentMa,
            'ma' => $currentMa,
            'mechanical_availability' => $currentMa,

            'status' => $this->getVehicleStatus($vehicle),

            'year'       => $vehicle->year,
            'created_at' => $vehicle->created_at,
            'updated_at' => $vehicle->updated_at,
        ];
    }

    /**
     * Payload realtime damage report untuk admin.
     */
    private function damageReportEventPayload(DamageReport $report): array
    {
        $report->loadMissing('vehicle');

        $vehicle = $report->vehicle;

        return [
            'id' => $report->id,
            'vehicle_id' => $report->vehicle_id,
            'driver_id' => $report->driver_id,
            'equipment_name' => $vehicle?->equipment_name,
            'plate_number' => $vehicle?->plate_number,
            'serial_number' => $vehicle?->serial_number,
            'initial_kpi' => $vehicle?->initial_kpi,
            'initial_hour_meter' => $vehicle?->initial_kpi,
            'current_hour_meter' => $this->getVehicleCurrentHourMeter($vehicle),
            'latest_hour_meter' => $this->getVehicleLatestHourMeter($vehicle),
            'final_hour_meter' => $this->getVehicleFinalHourMeter($vehicle),
            'hour_meter_terbaru' => $this->getVehicleCurrentHourMeter($vehicle),
            'target_availability' => $this->getVehicleTargetAvailability($vehicle),
            'current_ma' => $this->getVehicleCurrentMa($vehicle),
            'vehicle_status' => $this->getVehicleStatus($vehicle),
            'damage_type' => $report->damage_type,
            'description' => $report->description,
            'image' => $report->image,
            'image_url' => $this->imageUrl($report->image),
            'created_at' => $report->created_at,
            'updated_at' => $report->updated_at,
        ];
    }

    private function getVehicleTargetAvailability(?Vehicle $vehicle)
    {
        if (!$vehicle) {
            return 90;
        }

        return $this->firstValue([
            $this->hasColumn('vehicles', 'target_availability') ? $vehicle->target_availability : null,
            $this->hasColumn('vehicles', 'target_ma') ? $vehicle->target_ma : null,
            90,
        ]);
    }

    private function getVehicleStatus(?Vehicle $vehicle): string
    {
        if (!$vehicle) {
            return 'active';
        }

        if ($this->hasColumn('vehicles', 'status')) {
            return $vehicle->status ?? 'active';
        }

        return 'active';
    }

    /**
     * Ambil HM terbaru dari vehicle.
     *
     * Urutan prioritas:
     * 1. current_hour_meter
     * 2. latest_hour_meter
     * 3. final_hour_meter
     * 4. initial_kpi sebagai fallback
     */
    private function getVehicleCurrentHourMeter(?Vehicle $vehicle)
    {
        if (!$vehicle) {
            return null;
        }

        return $this->firstValue([
            $this->hasColumn('vehicles', 'current_hour_meter') ? $vehicle->current_hour_meter : null,
            $this->hasColumn('vehicles', 'latest_hour_meter') ? $vehicle->latest_hour_meter : null,
            $this->hasColumn('vehicles', 'final_hour_meter') ? $vehicle->final_hour_meter : null,
            $vehicle->initial_kpi ?? null,
        ]);
    }

    private function getVehicleLatestHourMeter(?Vehicle $vehicle)
    {
        if (!$vehicle) {
            return null;
        }

        return $this->firstValue([
            $this->hasColumn('vehicles', 'latest_hour_meter') ? $vehicle->latest_hour_meter : null,
            $this->hasColumn('vehicles', 'current_hour_meter') ? $vehicle->current_hour_meter : null,
            $this->hasColumn('vehicles', 'final_hour_meter') ? $vehicle->final_hour_meter : null,
            $vehicle->initial_kpi ?? null,
        ]);
    }

    private function getVehicleFinalHourMeter(?Vehicle $vehicle)
    {
        if (!$vehicle) {
            return null;
        }

        return $this->firstValue([
            $this->hasColumn('vehicles', 'final_hour_meter') ? $vehicle->final_hour_meter : null,
            $this->hasColumn('vehicles', 'current_hour_meter') ? $vehicle->current_hour_meter : null,
            $this->hasColumn('vehicles', 'latest_hour_meter') ? $vehicle->latest_hour_meter : null,
            $vehicle->initial_kpi ?? null,
        ]);
    }

    private function getVehicleCurrentMa(?Vehicle $vehicle)
    {
        if (!$vehicle) {
            return null;
        }

        return $this->firstValue([
            $this->hasColumn('vehicles', 'current_ma') ? $vehicle->current_ma : null,
            $this->hasColumn('vehicles', 'ma') ? $vehicle->ma : null,
            $this->hasColumn('vehicles', 'mechanical_availability') ? $vehicle->mechanical_availability : null,
            null,
        ]);
    }

    private function imageUrl(?string $path): ?string
    {
        if (!$path || $path === '-') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return url('storage/' . ltrim($path, '/'));
    }

    private function firstValue(array $values)
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Publish realtime event ke Node.
     *
     * Dibuat aman supaya kalau realtime gagal,
     * proses utama tetap berhasil.
     */
    private function publishRealtimeEvent(string $event, array $payload, array $channels): void
    {
        try {
            NodeEventPublisher::publish($event, $payload, $channels);
        } catch (\Throwable $error) {
            logger()->error('Realtime damage report event failed', [
                'event' => $event,
                'error' => $error->getMessage(),
            ]);
        }
    }
}
