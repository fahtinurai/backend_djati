<?php

namespace App\Http\Controllers\Api\Technician;

use App\Http\Controllers\Controller;
use App\Models\ServiceBooking;
use Illuminate\Http\Request;
use App\Services\FcmService;
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

        $q = ServiceBooking::with([
                'damageReport.vehicle',
                'damageReport.driver',
            ])
            ->orderByRaw("CASE status
                WHEN 'in_progress' THEN 0
                WHEN 'approved' THEN 1
                WHEN 'rescheduled' THEN 2
                ELSE 9 END")
            ->orderBy('scheduled_at', 'asc')
            ->latest('updated_at');

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
        $booking->load(['damageReport.vehicle', 'damageReport.driver']);
        return response()->json($booking);
    }

    /**
     * Teknisi mulai kerja.
     * otomatis:
     * - status -> in_progress
     * - started_at -> now() (kalau kolom ada)
     * - notif driver (WAJIB untuk kasus kamu)
     */
    public function start(Request $request, ServiceBooking $booking, FcmService $fcm)
    {
        // ✅ pastikan relasi ready untuk notif + plate
        $booking->load(['damageReport.vehicle', 'damageReport.driver']);

        if (!in_array($booking->status, ['approved', 'rescheduled'], true)) {
            return response()->json(['message' => 'Job tidak bisa dimulai.'], 422);
        }

        $payload = ['status' => 'in_progress'];

        if ($this->hasColumn($booking, 'started_at')) {
            $payload['started_at'] = now();
        }

        $booking->update($payload);
        $booking->refresh()->load(['damageReport.vehicle', 'damageReport.driver']);

        // ISO datetime untuk sinkronisasi frontend (Node/event)
        $startedIso = $this->hasColumn($booking, 'started_at')
            ? optional($booking->started_at)->toISOString()
            : null;
        $updatedIso = optional($booking->updated_at)->toISOString();

        // ✅ NOTIF DRIVER: servis dimulai
        try {
            $report = $booking->damageReport;
            $driver = $report?->driver;
            $plate  = $report?->vehicle?->plate_number ?? '-';

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
                        // opsional: kalau UI driver butuh timestamp start
                        'started_at' => (string) ($startedIso ?? ''),
                    ]
                );
            }
        } catch (\Throwable $e) {}

        // Optional: node event ke admin/teknisi UI realtime (ISO)
        try {
            NodeEventPublisher::publish('service_job.started', [
                'booking_id' => (int) $booking->id,
                'damage_report_id' => (int) $booking->damage_report_id,
                'status' => (string) $booking->status,
                'started_at' => $startedIso,
                'updated_at' => $updatedIso,
            ], ['admin', 'technician']);
        } catch (\Throwable $e) {}

        return response()->json(['message' => 'Job dimulai', 'data' => $booking]);
    }

    /**
     * Teknisi selesai kerja.
     * otomatis:
     * - status -> completed
     * - completed_at -> now() (kalau kolom ada)
     * - ✅ update damage_reports.status -> selesai (biar UI driver konsisten)
     * - notif driver (WAJIB)
     */
    public function complete(Request $request, ServiceBooking $booking, FcmService $fcm)
    {
        // ✅ pastikan relasi ready
        $booking->load(['damageReport.vehicle', 'damageReport.driver']);

        if (!in_array($booking->status, ['in_progress'], true)) {
            return response()->json(['message' => 'Job belum dimulai / tidak bisa diselesaikan.'], 422);
        }

        $payload = ['status' => 'completed'];

        if ($this->hasColumn($booking, 'completed_at')) {
            $payload['completed_at'] = now();
        }

        $booking->update($payload);
        $booking->refresh()->load(['damageReport.vehicle', 'damageReport.driver']);

        // ISO datetime untuk sinkronisasi frontend (Node/event)
        $completedIso = $this->hasColumn($booking, 'completed_at')
            ? optional($booking->completed_at)->toISOString()
            : null;
        $updatedIso = optional($booking->updated_at)->toISOString();

        // ✅ SINKRON REPORT: supaya driver yang liat status report juga ikut "selesai"
        try {
            $report = $booking->damageReport;
            if ($report && $this->hasColumnOnTable($report->getTable(), 'status')) {
                // kalau kamu memang punya kolom status di damage_reports (umumnya ada)
                $report->update(['status' => 'selesai']);
            }
        } catch (\Throwable $e) {}

        // ✅ NOTIF DRIVER: servis selesai
        try {
            $report = $booking->damageReport;
            $driver = $report?->driver;
            $plate  = $report?->vehicle?->plate_number ?? '-';

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
                        // opsional: kalau UI driver butuh timestamp selesai
                        'completed_at' => (string) ($completedIso ?? ''),
                    ]
                );
            }
        } catch (\Throwable $e) {}

        try {
            NodeEventPublisher::publish('service_job.completed', [
                'booking_id' => (int) $booking->id,
                'damage_report_id' => (int) $booking->damage_report_id,
                'status' => (string) $booking->status,
                'completed_at' => $completedIso,
                'updated_at' => $updatedIso,
            ], ['admin', 'technician']);
        } catch (\Throwable $e) {}

        return response()->json(['message' => 'Job selesai', 'data' => $booking]);
    }

    /**
     * Helper: cek kolom ada di service_bookings (started_at/completed_at).
     */
    private function hasColumn(ServiceBooking $booking, string $column): bool
    {
        try {
            return \Schema::hasColumn($booking->getTable(), $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Helper aman untuk cek kolom di tabel lain (misal damage_reports.status).
     */
    private function hasColumnOnTable(string $table, string $column): bool
    {
        try {
            return \Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
