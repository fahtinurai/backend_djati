<?php

namespace App\Http\Controllers\Api\Technician;

use App\Http\Controllers\Controller;
use App\Models\ServiceBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\NodeEventPublisher;

class ServiceJobController extends Controller
{
    /**
     * List job untuk teknisi:
     * - queue  : approved, rescheduled
     * - active : approved, rescheduled, in_progress
     * - all    : semua
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
            ->orderByRaw("CASE status
                WHEN 'in_progress' THEN 0
                WHEN 'approved' THEN 1
                WHEN 'rescheduled' THEN 2
                ELSE 9 END")
            ->orderBy('scheduled_at', 'asc')
            ->latest('updated_at');

        /*
        |--------------------------------------------------------------------------
        | Optional filter teknisi
        |--------------------------------------------------------------------------
        | Kalau kolom technician_id sudah ada dan admin mengirim technician_id,
        | teknisi hanya melihat job miliknya.
        |
        | Kalau kamu ingin semua teknisi bisa melihat semua job, hapus blok ini.
        */
        if ($this->hasColumnOnTable('service_bookings', 'technician_id')) {
            $q->where(function ($query) use ($technician) {
                $query->whereNull('technician_id')
                    ->orWhere('technician_id', $technician->id);
            });
        }

        if ($status === 'queue') {
            $q->whereIn('status', ['approved', 'rescheduled']);
        } elseif ($status === 'active') {
            $q->whereIn('status', ['approved', 'rescheduled', 'in_progress']);
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
     * otomatis:
     * - status -> in_progress
     * - started_at -> now()
     * - technician_id -> teknisi login jika belum ada
     * - note_technician opsional
     * - notif driver tidak memblokir proses
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

        if (!in_array($booking->status, ['approved', 'rescheduled'], true)) {
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

        if ($this->hasColumn($booking, 'technician_id') && empty($booking->technician_id)) {
            $payload['technician_id'] = $request->user()->id;
        }

        if ($this->hasColumn($booking, 'note_technician') && $request->filled('note_technician')) {
            $payload['note_technician'] = $request->note_technician;
        }

        $booking->update($payload);

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

        /*
        |--------------------------------------------------------------------------
        | FCM ke driver
        |--------------------------------------------------------------------------
        | Dipanggil lazy agar FIREBASE_CREDENTIALS yang belum diset tidak
        | menggagalkan start job.
        */
        try {
            $fcm = app(\App\Services\FcmService::class);

            $report = $booking->damageReport;
            $driver = $report?->driver;
            $plate  = $report?->vehicle?->plate_number
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

        /*
        |--------------------------------------------------------------------------
        | Node event
        |--------------------------------------------------------------------------
        */
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
     * otomatis:
     * - status -> completed
     * - completed_at -> now()
     * - note_technician opsional
     * - mttr / mtbf / ma opsional
     * - damage_reports.status -> selesai jika kolom ada
     * - notif driver tidak memblokir proses
     */
    public function complete(Request $request, ServiceBooking $booking)
    {
        $this->authorizeTechnicianAccess($request, $booking);

        $request->validate([
            'note_technician' => 'nullable|string|max:1000',
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

        $payload = [
            'status' => 'completed',
        ];

        if ($this->hasColumn($booking, 'completed_at')) {
            $payload['completed_at'] = now();
        }

        if ($this->hasColumn($booking, 'technician_id') && empty($booking->technician_id)) {
            $payload['technician_id'] = $request->user()->id;
        }

        if ($this->hasColumn($booking, 'note_technician') && $request->filled('note_technician')) {
            $payload['note_technician'] = $request->note_technician;
        }

        if ($this->hasColumn($booking, 'mttr') && $request->filled('mttr')) {
            $payload['mttr'] = $request->mttr;
        }

        if ($this->hasColumn($booking, 'mtbf') && $request->filled('mtbf')) {
            $payload['mtbf'] = $request->mtbf;
        }

        if ($this->hasColumn($booking, 'ma') && $request->filled('ma')) {
            $payload['ma'] = $request->ma;
        }

        $booking->update($payload);

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
        | Sinkron status damage report
        |--------------------------------------------------------------------------
        */
        try {
            $report = $booking->damageReport;

            if ($report && $this->hasColumnOnTable($report->getTable(), 'status')) {
                $report->update([
                    'status' => 'selesai',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal update damage report status selesai', [
                'booking_id' => $booking->id ?? null,
                'damage_report_id' => $booking->damage_report_id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | FCM ke driver
        |--------------------------------------------------------------------------
        */
        try {
            $fcm = app(\App\Services\FcmService::class);

            $report = $booking->damageReport;
            $driver = $report?->driver;
            $plate  = $report?->vehicle?->plate_number
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
        | Node event
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
     */
    private function authorizeTechnicianAccess(Request $request, ServiceBooking $booking): void
    {
        if (!$this->hasColumnOnTable('service_bookings', 'technician_id')) {
            return;
        }

        if (!$booking->technician_id) {
            return;
        }

        if ((int) $booking->technician_id !== (int) $request->user()->id) {
            abort(response()->json([
                'message' => 'Forbidden. Job ini bukan milik teknisi yang login.',
            ], 403));
        }
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