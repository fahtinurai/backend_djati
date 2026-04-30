<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceBooking;
use Illuminate\Http\Request;
use App\Services\FcmService;
use App\Services\NodeEventPublisher;
use App\Services\FirestoreService;

class ServiceBookingApprovalController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'requested');

        $q = ServiceBooking::with([
            'damageReport.vehicle',
            'damageReport.driver',
        ])->latest();

        // status=all -> tampilkan semua
        if ($status && $status !== 'all') {
            $q->where('status', $status);
        }

        return response()->json($q->get());
    }

    /**
     * Approve / set jadwal booking
     * - scheduled_at ditentukan admin
     * - estimated_finish_at opsional
     */
    public function approve(
        Request $request,
        ServiceBooking $booking,
        FcmService $fcm,
        FirestoreService $fs
    ) {
        $request->validate([
            'scheduled_at' => 'required|date',
            'estimated_finish_at' => 'nullable|date|after_or_equal:scheduled_at',
            'note_admin' => 'nullable|string',
        ]);

        if (!in_array($booking->status, ['requested', 'rescheduled'], true)) {
            return response()->json([
                'message' => 'Booking tidak dalam status yang bisa di-approve.'
            ], 422);
        }

        $booking->update([
            'status' => 'approved',
            'scheduled_at' => $request->scheduled_at,
            'estimated_finish_at' => $request->estimated_finish_at,
            'note_admin' => $request->note_admin,
        ]);

        $booking->load(['damageReport.vehicle', 'damageReport.driver']);

        // ISO (biar sinkron di mobile/web)
        $scheduledIso = optional($booking->scheduled_at)->toISOString();
        $finishIso    = optional($booking->estimated_finish_at)->toISOString();
        $preferredIso = optional($booking->preferred_at)->toISOString(); // opsional
        $updatedIso   = optional($booking->updated_at)->toISOString();

        // =========================
        // Firestore + FCM ke DRIVER
        // =========================
        try {
            $report = $booking->damageReport;
            if ($report && $report->driver) {
                $plate = $report?->vehicle?->plate_number ?? '-';

                // 1) simpan ke Firestore (inbox/riwayat)
                $fs->pushUserNotification((int) $report->driver->id, [
                    'title' => 'Booking Servis Disetujui',
                    'body'  => 'Jadwal servis untuk kendaraan ' . $plate . ' sudah ditetapkan.',
                    'type'  => 'service_booking',
                    'role'  => 'driver',
                    'data'  => [
                        'report_id' => (int) $report->id,
                        'booking_id' => (int) $booking->id,
                        'status' => (string) $booking->status,
                        'preferred_at' => (string) ($preferredIso ?? ''), // opsional
                        'scheduled_at' => (string) ($scheduledIso ?? ''),
                        'estimated_finish_at' => (string) ($finishIso ?? ''),
                    ],
                ]);

                // 2) kirim FCM push (real-time)
                $fcm->sendToUser(
                    $report->driver,
                    'Booking Servis Disetujui',
                    'Jadwal servis untuk kendaraan ' . $plate . ' sudah ditetapkan.',
                    [
                        'type' => 'service_booking',
                        'role' => 'driver',
                        'report_id' => (string) $report->id,
                        'booking_id' => (string) $booking->id,
                        'status' => (string) $booking->status,
                        // opsional: bisa dipakai UI buat highlight preferensi/jadwal
                        'preferred_at' => (string) ($preferredIso ?? ''),
                        'scheduled_at' => (string) ($scheduledIso ?? ''),
                    ]
                );
            }
        } catch (\Throwable $e) {
            // jangan bikin approve gagal
        }

        // =========================
        // Node event (web admin realtime)
        // =========================
        try {
            NodeEventPublisher::publish('service_booking.approved', [
                'booking_id' => (int) $booking->id,
                'damage_report_id' => (int) $booking->damage_report_id,
                'status' => (string) $booking->status,
                'preferred_at' => $preferredIso, // opsional
                'scheduled_at' => $scheduledIso,
                'estimated_finish_at' => $finishIso,
                'updated_at' => $updatedIso,
            ], ['admin']);
        } catch (\Throwable $e) {}

        return response()->json([
            'message' => 'Booking di-approve',
            'data' => $booking,
        ]);
    }

    /**
     * Reschedule
     */
    public function reschedule(
        Request $request,
        ServiceBooking $booking,
        FcmService $fcm,
        FirestoreService $fs
    ) {
        $request->validate([
            'scheduled_at' => 'required|date',
            'estimated_finish_at' => 'nullable|date|after_or_equal:scheduled_at',
            'note_admin' => 'nullable|string',
        ]);

        if (!in_array($booking->status, ['approved', 'requested', 'rescheduled'], true)) {
            return response()->json([
                'message' => 'Booking tidak bisa di-reschedule.'
            ], 422);
        }

        $booking->update([
            'status' => 'rescheduled',
            'scheduled_at' => $request->scheduled_at,
            'estimated_finish_at' => $request->estimated_finish_at,
            'note_admin' => $request->note_admin,
        ]);

        $booking->load(['damageReport.vehicle', 'damageReport.driver']);

        // ISO
        $scheduledIso = optional($booking->scheduled_at)->toISOString();
        $finishIso    = optional($booking->estimated_finish_at)->toISOString();
        $preferredIso = optional($booking->preferred_at)->toISOString(); // opsional
        $updatedIso   = optional($booking->updated_at)->toISOString();

        try {
            $report = $booking->damageReport;
            if ($report && $report->driver) {
                $plate = $report?->vehicle?->plate_number ?? '-';

                // Firestore
                $fs->pushUserNotification((int) $report->driver->id, [
                    'title' => 'Jadwal Booking Diubah',
                    'body'  => 'Jadwal servis kendaraan ' . $plate . ' telah diubah.',
                    'type'  => 'service_booking',
                    'role'  => 'driver',
                    'data'  => [
                        'report_id' => (int) $report->id,
                        'booking_id' => (int) $booking->id,
                        'status' => (string) $booking->status,
                        'preferred_at' => (string) ($preferredIso ?? ''), // opsional
                        'scheduled_at' => (string) ($scheduledIso ?? ''),
                        'estimated_finish_at' => (string) ($finishIso ?? ''),
                    ],
                ]);

                // FCM
                $fcm->sendToUser(
                    $report->driver,
                    'Jadwal Booking Diubah',
                    'Jadwal servis kendaraan ' . $plate . ' telah diubah.',
                    [
                        'type' => 'service_booking',
                        'role' => 'driver',
                        'report_id' => (string) $report->id,
                        'booking_id' => (string) $booking->id,
                        'status' => (string) $booking->status,
                        'preferred_at' => (string) ($preferredIso ?? ''),
                        'scheduled_at' => (string) ($scheduledIso ?? ''),
                    ]
                );
            }
        } catch (\Throwable $e) {}

        // Node event (opsional tapi konsisten ISO)
        try {
            NodeEventPublisher::publish('service_booking.rescheduled', [
                'booking_id' => (int) $booking->id,
                'damage_report_id' => (int) $booking->damage_report_id,
                'status' => (string) $booking->status,
                'preferred_at' => $preferredIso, // opsional
                'scheduled_at' => $scheduledIso,
                'estimated_finish_at' => $finishIso,
                'updated_at' => $updatedIso,
            ], ['admin']);
        } catch (\Throwable $e) {}

        return response()->json([
            'message' => 'Booking di-reschedule',
            'data' => $booking,
        ]);
    }

    public function cancel(
        Request $request,
        ServiceBooking $booking,
        FcmService $fcm,
        FirestoreService $fs
    ) {
        $request->validate([
            'note_admin' => 'nullable|string',
        ]);

        $booking->update([
            'status' => 'canceled',
            'note_admin' => $request->note_admin,
        ]);

        $booking->load(['damageReport.vehicle', 'damageReport.driver']);

        // ISO
        $preferredIso = optional($booking->preferred_at)->toISOString(); // opsional
        $updatedIso   = optional($booking->updated_at)->toISOString();

        try {
            $report = $booking->damageReport;
            if ($report && $report->driver) {
                $plate = $report?->vehicle?->plate_number ?? '-';

                // Firestore
                $fs->pushUserNotification((int) $report->driver->id, [
                    'title' => 'Booking Dibatalkan',
                    'body'  => 'Booking servis kendaraan ' . $plate . ' dibatalkan admin.',
                    'type'  => 'service_booking',
                    'role'  => 'driver',
                    'data'  => [
                        'report_id' => (int) $report->id,
                        'booking_id' => (int) $booking->id,
                        'status' => (string) $booking->status,
                        'preferred_at' => (string) ($preferredIso ?? ''), // opsional
                    ],
                ]);

                // FCM
                $fcm->sendToUser(
                    $report->driver,
                    'Booking Dibatalkan',
                    'Booking servis kendaraan ' . $plate . ' dibatalkan admin.',
                    [
                        'type' => 'service_booking',
                        'role' => 'driver',
                        'report_id' => (string) $report->id,
                        'booking_id' => (string) $booking->id,
                        'status' => (string) $booking->status,
                        'preferred_at' => (string) ($preferredIso ?? ''),
                    ]
                );
            }
        } catch (\Throwable $e) {}

        // Node event (opsional)
        try {
            NodeEventPublisher::publish('service_booking.canceled', [
                'booking_id' => (int) $booking->id,
                'damage_report_id' => (int) $booking->damage_report_id,
                'status' => (string) $booking->status,
                'preferred_at' => $preferredIso, // opsional
                'updated_at' => $updatedIso,
            ], ['admin']);
        } catch (\Throwable $e) {}

        return response()->json([
            'message' => 'Booking dibatalkan',
            'data' => $booking,
        ]);
    }
}
