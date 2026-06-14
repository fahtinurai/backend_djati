<?php

namespace App\Http\Controllers\Api\Technician;

use App\Http\Controllers\Controller;
use App\Models\ServiceBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\NodeEventPublisher;

class ServiceJobController extends Controller
{
    /**
     * List job untuk teknisi:
     * - queue  : approved, scheduled, rescheduled
     * - active : approved, scheduled, rescheduled, in_progress
     * - all    : semua job milik teknisi, kecuali requested/canceled/rejected
     *
     * PENTING:
     * Teknisi hanya boleh melihat job yang sudah dipilih admin.
     * Jadi technician_id wajib sama dengan teknisi login.
     */
    public function index(Request $request)
    {
        $status = $request->query('status', 'active');
        $technician = $request->user();

        $q = ServiceBooking::with([
                'damageReport.vehicle',
                'damageReport.driver',
                'vehicle',
                'driver',
                'technician',
            ])
            ->where('technician_id', $technician->id)
            ->whereNotIn('status', [
                'requested',
                'pending',
                'reported',
                'menunggu',
                'canceled',
                'cancelled',
                'dibatalkan',
                'rejected',
                'ditolak',
            ])
            ->orderByRaw("CASE status
                WHEN 'in_progress' THEN 0
                WHEN 'approved' THEN 1
                WHEN 'scheduled' THEN 2
                WHEN 'rescheduled' THEN 3
                WHEN 'completed' THEN 4
                WHEN 'finished' THEN 5
                WHEN 'selesai' THEN 6
                ELSE 9 END")
            ->orderBy('scheduled_at', 'asc')
            ->latest('updated_at');

        if ($status === 'queue') {
            $q->whereIn('status', [
                'approved',
                'scheduled',
                'rescheduled',
            ]);
        } elseif ($status === 'active') {
            $q->whereIn('status', [
                'approved',
                'scheduled',
                'rescheduled',
                'in_progress',
            ]);
        } elseif ($status === 'completed') {
            $q->whereIn('status', [
                'completed',
                'finished',
                'selesai',
            ]);
        } elseif ($status !== 'all') {
            $q->where('status', $status);
        }

        return response()->json($q->get());
    }

    public function show(Request $request, ServiceBooking $booking)
    {
        $this->authorizeTechnicianAccess($request, $booking);

        $booking->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        return response()->json($booking);
    }

    /**
     * Teknisi mulai kerja.
     *
     * Teknisi tidak boleh mengambil job sendiri.
     * technician_id harus sudah diisi admin saat approve.
     */
    public function start(Request $request, ServiceBooking $booking)
    {
        $this->authorizeTechnicianAccess($request, $booking);

        $request->validate([
            'note_technician' => 'nullable|string|max:1000',
        ]);

        $booking->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        if (!in_array($booking->status, ['approved', 'scheduled', 'rescheduled'], true)) {
            return response()->json([
                'message' => 'Job tidak bisa dimulai.',
            ], 422);
        }

        $payload = [
            'status' => 'in_progress',
        ];

        if ($this->hasColumn($booking, 'started_at')) {
            $payload['started_at'] = now();
        }

        if ($this->hasColumn($booking, 'note_technician') && $request->filled('note_technician')) {
            $payload['note_technician'] = $request->note_technician;
        }

        $booking->forceFill($payload)->save();

        $booking->refresh()->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        $startedIso = $this->hasColumn($booking, 'started_at')
            ? optional($booking->started_at)->toISOString()
            : null;

        $updatedIso = optional($booking->updated_at)->toISOString();

        try {
            $fcm = app(\App\Services\FcmService::class);

            $report = $booking->damageReport;
            $driver = $report?->driver;
            $plate = $report?->vehicle?->plate_number
                ?? $booking?->vehicle?->plate_number
                ?? '-';

            if ($driver) {
                $fcm->sendToUser(
                    $driver,
                    'Servis Dimulai',
                    'Servis kendaraan ' . $plate . ' sedang dikerjakan teknisi.',
                    [
                        'type' => 'service_job',
                        'role' => 'driver',
                        'report_id' => (string) ($report->id ?? $booking->damage_report_id),
                        'booking_id' => (string) $booking->id,
                        'status' => (string) $booking->status,
                        'started_at' => (string) ($startedIso ?? ''),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal kirim FCM service job started', [
                'booking_id' => $booking->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            NodeEventPublisher::publish('service_job.started', [
                'booking_id' => (int) $booking->id,
                'damage_report_id' => (int) $booking->damage_report_id,
                'driver_id' => $booking->driver_id ? (int) $booking->driver_id : null,
                'vehicle_id' => $booking->vehicle_id ? (int) $booking->vehicle_id : null,
                'technician_id' => $booking->technician_id ? (int) $booking->technician_id : null,
                'status' => (string) $booking->status,
                'started_at' => $startedIso,
                'updated_at' => $updatedIso,
            ], ['admin', 'technician', 'driver']);
        } catch (\Throwable $e) {
            Log::warning('Gagal publish node event service_job.started', [
                'booking_id' => $booking->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Job dimulai',
            'data' => $booking,
        ]);
    }

    /**
     * Teknisi selesai kerja.
     *
     * PENTING:
     * Flutter sekarang mengirim data mentah:
     * - final_hour_meter
     * - current_hour_meter
     * - latest_hour_meter
     * - total_repair_time
     * - total_operational_time
     * - failure_count
     * - actual_operating_hours
     * - breakdown_hours
     *
     * Backend yang menghitung:
     * - mttr
     * - mtbf
     * - ma
     */
    public function complete(Request $request, ServiceBooking $booking)
    {
        $this->authorizeTechnicianAccess($request, $booking);

        $request->validate([
            'note_technician' => 'nullable|string|max:1000',

            /*
            |--------------------------------------------------------------------------
            | FIELD BARU DARI FLUTTER
            |--------------------------------------------------------------------------
            */
            'final_hour_meter' => 'nullable|numeric|min:0',
            'current_hour_meter' => 'nullable|numeric|min:0',
            'latest_hour_meter' => 'nullable|numeric|min:0',

            'total_repair_time' => 'nullable|numeric|min:0',
            'repair_time' => 'nullable|numeric|min:0',
            'repair_time_hours' => 'nullable|numeric|min:0',

            'total_operational_time' => 'nullable|numeric|min:0',
            'operational_time' => 'nullable|numeric|min:0',
            'operational_time_hours' => 'nullable|numeric|min:0',

            'failure_count' => 'nullable|numeric|min:1',
            'number_of_failures' => 'nullable|numeric|min:1',
            'failures' => 'nullable|numeric|min:1',

            'actual_operating_hours' => 'nullable|numeric|min:0',
            'actual_operation_hours' => 'nullable|numeric|min:0',

            'breakdown_hours' => 'nullable|numeric|min:0',
            'breakdown_time' => 'nullable|numeric|min:0',

            /*
            |--------------------------------------------------------------------------
            | FIELD LAMA
            |--------------------------------------------------------------------------
            |
            | Tetap diterima untuk kompatibilitas, tapi flow baru sebaiknya
            | menghitung dari data mentah di backend.
            |
            */
            'mttr' => 'nullable|numeric|min:0',
            'mtbf' => 'nullable|numeric|min:0',
            'ma' => 'nullable|numeric|min:0|max:100',
        ]);

        $booking->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        if (!in_array($booking->status, ['in_progress'], true)) {
            return response()->json([
                'message' => 'Job belum dimulai / tidak bisa diselesaikan.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | AMBIL DATA MENTAH DARI REQUEST
        |--------------------------------------------------------------------------
        */
        $finalHourMeter = $this->numberFromRequest($request, [
            'final_hour_meter',
            'current_hour_meter',
            'latest_hour_meter',
        ]);

        $totalRepairTime = $this->numberFromRequest($request, [
            'total_repair_time',
            'repair_time',
            'repair_time_hours',
        ]);

        $totalOperationalTime = $this->numberFromRequest($request, [
            'total_operational_time',
            'operational_time',
            'operational_time_hours',
        ]);

        $failureCount = $this->numberFromRequest($request, [
            'failure_count',
            'number_of_failures',
            'failures',
        ]);

        $actualOperatingHours = $this->numberFromRequest($request, [
            'actual_operating_hours',
            'actual_operation_hours',
        ]);

        $breakdownHours = $this->numberFromRequest($request, [
            'breakdown_hours',
            'breakdown_time',
        ]);

        /*
        |--------------------------------------------------------------------------
        | HITUNG KPI DI BACKEND
        |--------------------------------------------------------------------------
        */
        $legacyMttr = $this->numberFromRequest($request, ['mttr']);
        $legacyMtbf = $this->numberFromRequest($request, ['mtbf']);
        $legacyMa = $this->numberFromRequest($request, ['ma']);

        $safeFailureCount = ($failureCount !== null && $failureCount > 0)
            ? $failureCount
            : null;

        $calculatedMttr = null;
        if ($totalRepairTime !== null && $safeFailureCount !== null) {
            $calculatedMttr = round($totalRepairTime / $safeFailureCount, 2);
        } elseif ($legacyMttr !== null) {
            $calculatedMttr = round($legacyMttr, 2);
        }

        $calculatedMtbf = null;
        if ($totalOperationalTime !== null && $safeFailureCount !== null) {
            $calculatedMtbf = round($totalOperationalTime / $safeFailureCount, 2);
        } elseif ($legacyMtbf !== null) {
            $calculatedMtbf = round($legacyMtbf, 2);
        }

        $calculatedMa = null;
        if (
            $actualOperatingHours !== null &&
            $breakdownHours !== null &&
            ($actualOperatingHours + $breakdownHours) > 0
        ) {
            $calculatedMa = round(
                ($actualOperatingHours / ($actualOperatingHours + $breakdownHours)) * 100,
                1
            );
        } elseif ($legacyMa !== null) {
            $calculatedMa = round($legacyMa, 1);
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE DATABASE
        |--------------------------------------------------------------------------
        */
        DB::transaction(function () use (
            $request,
            $booking,
            $finalHourMeter,
            $totalRepairTime,
            $totalOperationalTime,
            $failureCount,
            $actualOperatingHours,
            $breakdownHours,
            $calculatedMttr,
            $calculatedMtbf,
            $calculatedMa
        ) {
            /*
            |--------------------------------------------------------------------------
            | UPDATE SERVICE BOOKING
            |--------------------------------------------------------------------------
            */
            $payload = [
                'status' => 'completed',
            ];

            if ($this->hasColumn($booking, 'completed_at')) {
                $payload['completed_at'] = now();
            }

            if ($this->hasColumn($booking, 'note_technician') && $request->filled('note_technician')) {
                $payload['note_technician'] = $request->note_technician;
            }

            /*
            |--------------------------------------------------------------------------
            | SIMPAN HOUR METER TERBARU
            |--------------------------------------------------------------------------
            */
            if ($finalHourMeter !== null) {
                if ($this->hasColumn($booking, 'final_hour_meter')) {
                    $payload['final_hour_meter'] = $finalHourMeter;
                }

                if ($this->hasColumn($booking, 'current_hour_meter')) {
                    $payload['current_hour_meter'] = $finalHourMeter;
                }

                if ($this->hasColumn($booking, 'latest_hour_meter')) {
                    $payload['latest_hour_meter'] = $finalHourMeter;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | SIMPAN DATA MENTAH MAINTENANCE
            |--------------------------------------------------------------------------
            */
            if ($totalRepairTime !== null && $this->hasColumn($booking, 'total_repair_time')) {
                $payload['total_repair_time'] = $totalRepairTime;
            }

            if ($totalRepairTime !== null && $this->hasColumn($booking, 'repair_time')) {
                $payload['repair_time'] = $totalRepairTime;
            }

            if ($totalRepairTime !== null && $this->hasColumn($booking, 'repair_time_hours')) {
                $payload['repair_time_hours'] = $totalRepairTime;
            }

            if ($totalOperationalTime !== null && $this->hasColumn($booking, 'total_operational_time')) {
                $payload['total_operational_time'] = $totalOperationalTime;
            }

            if ($totalOperationalTime !== null && $this->hasColumn($booking, 'operational_time')) {
                $payload['operational_time'] = $totalOperationalTime;
            }

            if ($totalOperationalTime !== null && $this->hasColumn($booking, 'operational_time_hours')) {
                $payload['operational_time_hours'] = $totalOperationalTime;
            }

            if ($failureCount !== null && $this->hasColumn($booking, 'failure_count')) {
                $payload['failure_count'] = $failureCount;
            }

            if ($failureCount !== null && $this->hasColumn($booking, 'number_of_failures')) {
                $payload['number_of_failures'] = $failureCount;
            }

            if ($failureCount !== null && $this->hasColumn($booking, 'failures')) {
                $payload['failures'] = $failureCount;
            }

            if ($actualOperatingHours !== null && $this->hasColumn($booking, 'actual_operating_hours')) {
                $payload['actual_operating_hours'] = $actualOperatingHours;
            }

            if ($actualOperatingHours !== null && $this->hasColumn($booking, 'actual_operation_hours')) {
                $payload['actual_operation_hours'] = $actualOperatingHours;
            }

            if ($breakdownHours !== null && $this->hasColumn($booking, 'breakdown_hours')) {
                $payload['breakdown_hours'] = $breakdownHours;
            }

            if ($breakdownHours !== null && $this->hasColumn($booking, 'breakdown_time')) {
                $payload['breakdown_time'] = $breakdownHours;
            }

            /*
            |--------------------------------------------------------------------------
            | SIMPAN HASIL KPI BACKEND
            |--------------------------------------------------------------------------
            */
            if ($calculatedMttr !== null && $this->hasColumn($booking, 'mttr')) {
                $payload['mttr'] = $calculatedMttr;
            }

            if ($calculatedMtbf !== null && $this->hasColumn($booking, 'mtbf')) {
                $payload['mtbf'] = $calculatedMtbf;
            }

            if ($calculatedMa !== null && $this->hasColumn($booking, 'ma')) {
                $payload['ma'] = $calculatedMa;
            }

            $booking->forceFill($payload)->save();

            /*
            |--------------------------------------------------------------------------
            | UPDATE DAMAGE REPORT
            |--------------------------------------------------------------------------
            */
            $report = $booking->damageReport;

            if ($report) {
                $reportPayload = [];

                if ($this->hasColumnOnTable($report->getTable(), 'status')) {
                    $reportPayload['status'] = 'selesai';
                }

                if ($this->hasColumnOnTable($report->getTable(), 'completed_at')) {
                    $reportPayload['completed_at'] = now();
                }

                if ($this->hasColumnOnTable($report->getTable(), 'finished_at')) {
                    $reportPayload['finished_at'] = now();
                }

                if (!empty($reportPayload)) {
                    $report->forceFill($reportPayload)->save();
                }
            }

            /*
            |--------------------------------------------------------------------------
            | UPDATE VEHICLE
            |--------------------------------------------------------------------------
            |
            | Initial tidak diubah.
            | Yang diubah hanya current/latest/final.
            |
            */
            $vehicle = $this->getVehicleFromBooking($booking);

            if ($vehicle) {
                $vehiclePayload = [];

                if ($finalHourMeter !== null) {
                    if ($this->hasColumnOnTable($vehicle->getTable(), 'current_hour_meter')) {
                        $vehiclePayload['current_hour_meter'] = $finalHourMeter;
                    }

                    if ($this->hasColumnOnTable($vehicle->getTable(), 'latest_hour_meter')) {
                        $vehiclePayload['latest_hour_meter'] = $finalHourMeter;
                    }

                    if ($this->hasColumnOnTable($vehicle->getTable(), 'final_hour_meter')) {
                        $vehiclePayload['final_hour_meter'] = $finalHourMeter;
                    }
                }

                if ($calculatedMa !== null) {
                    if ($this->hasColumnOnTable($vehicle->getTable(), 'current_ma')) {
                        $vehiclePayload['current_ma'] = $calculatedMa;
                    }

                    if ($this->hasColumnOnTable($vehicle->getTable(), 'ma')) {
                        $vehiclePayload['ma'] = $calculatedMa;
                    }

                    if ($this->hasColumnOnTable($vehicle->getTable(), 'mechanical_availability')) {
                        $vehiclePayload['mechanical_availability'] = $calculatedMa;
                    }
                }

                if ($this->hasColumnOnTable($vehicle->getTable(), 'last_repair_at')) {
                    $vehiclePayload['last_repair_at'] = now();
                }

                if ($this->hasColumnOnTable($vehicle->getTable(), 'last_maintenance_at')) {
                    $vehiclePayload['last_maintenance_at'] = now();
                }

                if ($this->hasColumnOnTable($vehicle->getTable(), 'status')) {
                    $vehiclePayload['status'] = 'active';
                }

                if (!empty($vehiclePayload)) {
                    $vehicle->forceFill($vehiclePayload)->save();
                }
            }
        });

        $booking->refresh()->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        $completedIso = $this->hasColumn($booking, 'completed_at')
            ? optional($booking->completed_at)->toISOString()
            : null;

        $updatedIso = optional($booking->updated_at)->toISOString();

        /*
        |--------------------------------------------------------------------------
        | FCM DRIVER
        |--------------------------------------------------------------------------
        */
        try {
            $fcm = app(\App\Services\FcmService::class);

            $report = $booking->damageReport;
            $driver = $report?->driver ?? $booking?->driver;
            $plate = $report?->vehicle?->plate_number
                ?? $booking?->vehicle?->plate_number
                ?? '-';

            if ($driver) {
                $fcm->sendToUser(
                    $driver,
                    'Servis Selesai',
                    'Servis kendaraan ' . $plate . ' sudah selesai.',
                    [
                        'type' => 'service_job',
                        'role' => 'driver',
                        'report_id' => (string) ($report->id ?? $booking->damage_report_id),
                        'booking_id' => (string) $booking->id,
                        'status' => (string) $booking->status,
                        'completed_at' => (string) ($completedIso ?? ''),
                        'mttr' => (string) ($booking->mttr ?? ''),
                        'mtbf' => (string) ($booking->mtbf ?? ''),
                        'ma' => (string) ($booking->ma ?? ''),
                        'final_hour_meter' => (string) ($booking->final_hour_meter ?? $booking->current_hour_meter ?? ''),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal kirim FCM service job completed', [
                'booking_id' => $booking->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | NODE EVENT
        |--------------------------------------------------------------------------
        */
        try {
            NodeEventPublisher::publish('service_job.completed', [
                'booking_id' => (int) $booking->id,
                'damage_report_id' => (int) $booking->damage_report_id,
                'driver_id' => $booking->driver_id ? (int) $booking->driver_id : null,
                'vehicle_id' => $booking->vehicle_id ? (int) $booking->vehicle_id : null,
                'technician_id' => $booking->technician_id ? (int) $booking->technician_id : null,
                'status' => (string) $booking->status,
                'completed_at' => $completedIso,
                'updated_at' => $updatedIso,
                'final_hour_meter' => $booking->final_hour_meter ?? $booking->current_hour_meter ?? null,
                'current_hour_meter' => $booking->current_hour_meter ?? null,
                'total_repair_time' => $booking->total_repair_time ?? null,
                'total_operational_time' => $booking->total_operational_time ?? null,
                'failure_count' => $booking->failure_count ?? null,
                'actual_operating_hours' => $booking->actual_operating_hours ?? null,
                'breakdown_hours' => $booking->breakdown_hours ?? null,
                'mttr' => $booking->mttr ?? null,
                'mtbf' => $booking->mtbf ?? null,
                'ma' => $booking->ma ?? null,
            ], ['admin', 'technician', 'driver']);
        } catch (\Throwable $e) {
            Log::warning('Gagal publish node event service_job.completed', [
                'booking_id' => $booking->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Job selesai',
            'data' => $booking,
        ]);
    }

    /**
     * Helper: validasi akses teknisi ke booking.
     *
     * PENTING:
     * Kalau technician_id kosong, teknisi tidak boleh akses.
     */
    private function authorizeTechnicianAccess(Request $request, ServiceBooking $booking): void
    {
        if (!$this->hasColumnOnTable('service_bookings', 'technician_id')) {
            abort(response()->json([
                'message' => 'Kolom technician_id belum tersedia pada service_bookings.',
            ], 500));
        }

        if (!$booking->technician_id) {
            abort(response()->json([
                'message' => 'Job ini belum ditugaskan admin ke teknisi.',
            ], 403));
        }

        if ((int) $booking->technician_id !== (int) $request->user()->id) {
            abort(response()->json([
                'message' => 'Forbidden. Job ini bukan milik teknisi yang login.',
            ], 403));
        }

        if (in_array($booking->status, [
            'requested',
            'pending',
            'reported',
            'menunggu',
            'canceled',
            'cancelled',
            'dibatalkan',
            'rejected',
            'ditolak',
        ], true)) {
            abort(response()->json([
                'message' => 'Job ini belum aktif untuk teknisi.',
            ], 403));
        }
    }

    /**
     * Ambil angka dari request berdasarkan beberapa kemungkinan key.
     */
    private function numberFromRequest(Request $request, array $keys): ?float
    {
        foreach ($keys as $key) {
            if ($request->filled($key)) {
                $value = $request->input($key);

                if (is_string($value)) {
                    $value = str_replace(',', '.', trim($value));
                }

                if (is_numeric($value)) {
                    return (float) $value;
                }
            }
        }

        return null;
    }

    /**
     * Ambil vehicle dari service booking.
     */
    private function getVehicleFromBooking(ServiceBooking $booking)
    {
        if ($booking->relationLoaded('vehicle') && $booking->vehicle) {
            return $booking->vehicle;
        }

        if ($booking->vehicle) {
            return $booking->vehicle;
        }

        if (
            $booking->relationLoaded('damageReport') &&
            $booking->damageReport &&
            $booking->damageReport->relationLoaded('vehicle') &&
            $booking->damageReport->vehicle
        ) {
            return $booking->damageReport->vehicle;
        }

        if ($booking->damageReport && $booking->damageReport->vehicle) {
            return $booking->damageReport->vehicle;
        }

        return null;
    }

    /**
     * Helper: cek kolom ada di service_bookings.
     */
    private function hasColumn(ServiceBooking $booking, string $column): bool
    {
        try {
            return Schema::hasColumn($booking->getTable(), $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Helper aman untuk cek kolom di tabel lain.
     */
    private function hasColumnOnTable(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }
}