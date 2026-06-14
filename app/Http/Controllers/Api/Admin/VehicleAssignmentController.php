<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\NodeEventPublisher;

class VehicleAssignmentController extends Controller
{
    /**
     * GET /api/admin/vehicle-assignments
     *
     * Admin melihat semua assignment kendaraan ke driver.
     */
    public function index()
    {
        $assignments = VehicleAssignment::with(['vehicle', 'driver'])
            ->orderBy('assigned_at', 'desc')
            ->get()
            ->map(function ($assignment) {
                return $this->assignmentResponse($assignment);
            });

        return response()->json([
            'data' => $assignments,
        ]);
    }

    /**
     * POST /api/admin/vehicle-assignments
     *
     * Admin assign kendaraan ke driver.
     */
    public function store(Request $request)
    {
        $validated = $request->validate(
            [
                'vehicle_id' => 'required|exists:vehicles,id',
                'driver_id'  => 'required|exists:users,id',
            ],
            [
                'vehicle_id.required' => 'Kendaraan wajib dipilih.',
                'vehicle_id.exists'   => 'Kendaraan tidak ditemukan.',
                'driver_id.required'  => 'Driver wajib dipilih.',
                'driver_id.exists'    => 'Driver tidak ditemukan.',
            ]
        );

        $driver = User::findOrFail($validated['driver_id']);

        $driverRole = $this->resolveUserRole($driver);

        if ($driverRole !== 'driver') {
            return response()->json([
                'message' => 'User yang dipilih bukan driver.',
            ], 400);
        }

        try {
            $assignment = DB::transaction(function () use ($validated) {
                /**
                 * Cek kendaraan sudah dipakai driver lain atau belum.
                 */
                $vehicleAssigned = VehicleAssignment::where('vehicle_id', $validated['vehicle_id'])
                    ->lockForUpdate()
                    ->first();

                if ($vehicleAssigned) {
                    abort(response()->json([
                        'message' => 'Kendaraan sudah di-assign ke driver lain.',
                    ], 400));
                }

                /**
                 * Cek driver sudah punya kendaraan atau belum.
                 */
                $driverAssigned = VehicleAssignment::where('driver_id', $validated['driver_id'])
                    ->lockForUpdate()
                    ->first();

                if ($driverAssigned) {
                    abort(response()->json([
                        'message' => 'Driver sudah memiliki kendaraan.',
                    ], 400));
                }

                $assignment = VehicleAssignment::create([
                    'vehicle_id'  => $validated['vehicle_id'],
                    'driver_id'   => $validated['driver_id'],
                    'assigned_at' => now(),
                ]);

                $assignment->load(['vehicle', 'driver']);

                return $assignment;
            });

            $payload = $this->assignmentEventPayload($assignment);

            $this->publishRealtimeEvent('assignment.created', $payload, [
                'admin',
                'driver',
            ]);

            return response()->json([
                'message' => 'Kendaraan berhasil di-assign ke driver.',
                'data'    => $this->assignmentResponse($assignment),
            ], 201);
        } catch (\Throwable $error) {
            /**
             * Kalau error berasal dari abort(response()), langsung lempar response-nya.
             */
            if (method_exists($error, 'getResponse') && $error->getResponse()) {
                return $error->getResponse();
            }

            logger()->error('Create vehicle assignment failed', [
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'message' => 'Gagal menghubungkan kendaraan ke driver.',
            ], 500);
        }
    }

    /**
     * GET /api/driver/my-vehicle
     *
     * Driver melihat kendaraan yang sedang di-assign ke dirinya.
     */
    public function myVehicle(Request $request)
    {
        $assignment = VehicleAssignment::with(['vehicle', 'driver'])
            ->where('driver_id', $request->user()->id)
            ->first();

        if (!$assignment) {
            return response()->json([
                'message' => 'Belum ada kendaraan yang di-assign.',
            ], 404);
        }

        return response()->json([
            'data' => $this->assignmentResponse($assignment),
        ]);
    }

    /**
     * GET /api/admin/vehicle-assignments/{vehicleAssignment}
     */
    public function show(VehicleAssignment $vehicleAssignment)
    {
        $vehicleAssignment->load(['vehicle', 'driver']);

        return response()->json([
            'data' => $this->assignmentResponse($vehicleAssignment),
        ]);
    }

    /**
     * DELETE /api/admin/vehicle-assignments/{vehicleAssignment}
     *
     * Admin hapus assignment kendaraan.
     */
    public function destroy(VehicleAssignment $vehicleAssignment)
    {
        $vehicleAssignment->load(['vehicle', 'driver']);

        /*
        |--------------------------------------------------------------------------
        | BLOKIR UNASSIGN JIKA MASIH ADA REPORT / MAINTENANCE BERJALAN
        |--------------------------------------------------------------------------
        */
        if (
            method_exists($vehicleAssignment, 'hasRunningActivity') &&
            $vehicleAssignment->hasRunningActivity()
        ) {
            return response()->json([
                'message' => 'Kendaraan tidak dapat di-unassign karena masih memiliki report atau maintenance yang sedang berjalan.',
            ], 422);
        }

        $payload = $this->assignmentEventPayload($vehicleAssignment);

        $vehicleAssignment->delete();

        $this->publishRealtimeEvent('assignment.deleted', $payload, [
            'admin',
            'driver',
        ]);

        return response()->json([
            'message' => 'Assignment berhasil dihapus.',
        ]);
    }

    /**
     * Ambil role user secara aman.
     *
     * Tujuannya supaya tidak error meskipun struktur User berbeda:
     * - role berupa string: "driver"
     * - role berupa object relation: role->name
     * - role_name
     */
    private function resolveUserRole(User $user): string
    {
        try {
            if (method_exists($user, 'role')) {
                $user->loadMissing('role');
            }
        } catch (\Throwable $error) {
            // Abaikan jika relasi role tidak tersedia.
        }

        $role = $user->role ?? null;

        if (is_object($role)) {
            return strtolower((string) ($role->name ?? $role->role_name ?? ''));
        }

        if (is_string($role)) {
            return strtolower($role);
        }

        if (isset($user->role_name)) {
            return strtolower((string) $user->role_name);
        }

        return '';
    }

    /**
     * Format response assignment.
     *
     * Tetap mempertahankan struktur:
     * data -> assignment
     *
     * Tapi field vehicle dibuat lebih lengkap agar aman dipakai di frontend
     * dan driver page.
     */
    private function assignmentResponse(VehicleAssignment $assignment): array
    {
        $vehicle = $assignment->vehicle;
        $driver = $assignment->driver;

        return [
            'id'          => $assignment->id,
            'vehicle_id'  => $assignment->vehicle_id,
            'driver_id'   => $assignment->driver_id,
            'assigned_at' => $assignment->assigned_at,
            'created_at'  => $assignment->created_at,
            'updated_at'  => $assignment->updated_at,

            'vehicle' => $vehicle ? [
                'id' => $vehicle->id,

                'equipment_name' => $vehicle->equipment_name,
                'brand'          => $vehicle->brand,
                'model'          => $vehicle->model,
                'plate_number'   => $vehicle->plate_number,
                'serial_number'  => $vehicle->serial_number,

                /**
                 * Field lama backend.
                 */
                'initial_kpi' => $vehicle->initial_kpi,

                /**
                 * Field baru untuk frontend.
                 * VehiclesPage.jsx membaca ini sebagai Hour Meter Awal.
                 */
                'initial_hour_meter' => $vehicle->initial_hour_meter ?? $vehicle->initial_kpi,

                /**
                 * Target MA default 90 jika belum ada kolom / belum ada nilai.
                 */
                'target_availability' => $vehicle->target_availability ?? $vehicle->target_ma ?? 90,

                'year'   => $vehicle->year,
                'status' => $vehicle->status ?? $vehicle->unit_status ?? 'active',

                'created_at' => $vehicle->created_at,
                'updated_at' => $vehicle->updated_at,
            ] : null,

            'driver' => $driver ? [
                'id'    => $driver->id,
                'name'  => $driver->name,
                'email' => $driver->email ?? null,
                'role'  => $this->resolveUserRole($driver),
            ] : null,
        ];
    }

    /**
     * Payload realtime event.
     */
    private function assignmentEventPayload(VehicleAssignment $assignment): array
    {
        $vehicle = $assignment->vehicle;
        $driver = $assignment->driver;

        return [
            'assignment_id' => $assignment->id,

            'vehicle_id'     => $assignment->vehicle_id,
            'equipment_name' => $vehicle?->equipment_name,
            'brand'          => $vehicle?->brand,
            'model'          => $vehicle?->model,
            'plate_number'   => $vehicle?->plate_number,
            'serial_number'  => $vehicle?->serial_number,

            /**
             * Tetap kirim initial_kpi agar fitur lama tidak rusak.
             */
            'initial_kpi' => $vehicle?->initial_kpi,

            /**
             * Field baru untuk frontend.
             */
            'initial_hour_meter' => $vehicle?->initial_hour_meter ?? $vehicle?->initial_kpi,
            'target_availability' => $vehicle?->target_availability ?? $vehicle?->target_ma ?? 90,
            'vehicle_status' => $vehicle?->status ?? $vehicle?->unit_status ?? 'active',

            'driver_id'   => $assignment->driver_id,
            'driver_name' => $driver?->name,
            'driver_email' => $driver?->email ?? null,

            'assigned_at' => $assignment->assigned_at,
        ];
    }

    /**
     * Publish realtime event ke Node.
     *
     * Dibungkus try-catch agar kalau realtime gagal,
     * proses assign/unassign tetap berhasil.
     */
    private function publishRealtimeEvent(string $event, array $payload, array $channels): void
    {
        try {
            NodeEventPublisher::publish($event, $payload, $channels);
        } catch (\Throwable $error) {
            logger()->error('Realtime assignment event failed', [
                'event' => $event,
                'error' => $error->getMessage(),
            ]);
        }
    }
}