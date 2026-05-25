<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\NodeEventPublisher;

class ServiceBookingApprovalController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'requested');

        $q = ServiceBooking::with([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
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
     * - technician_id opsional/nullable, tapi disarankan dikirim dari admin web
     */
    public function approve(Request $request, ServiceBooking $booking)
    {
        $request->validate([
            'scheduled_at' => 'required|date',
            'estimated_finish_at' => 'nullable|date|after_or_equal:scheduled_at',
            'technician_id' => 'nullable|exists:users,id',
            'priority' => 'nullable|string|in:low,medium,high,critical',
            'note_admin' => 'nullable|string|max:1000',
        ]);

        if (!in_array($booking->status, ['requested', 'rescheduled'], true)) {
            return response()->json([
                'message' => 'Booking tidak dalam status yang bisa di-approve.',
            ], 422);
        }

        $booking->update([
            'status' => 'approved',
            'scheduled_at' => $request->scheduled_at,
            'estimated_finish_at' => $request->estimated_finish_at,
            'technician_id' => $request->technician_id,
            'priority' => $request->priority ?? $booking->priority ?? 'medium',
            'note_admin' => $request->note_admin,
        ]);

        $booking->refresh()->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        $scheduledIso = optional($booking->scheduled_at)->toISOString();
        $finishIso    = optional($booking->estimated_finish_at)->toISOString();
        $preferredIso = optional($booking->preferred_at)->toISOString();
        $updatedIso   = optional($booking->updated_at)->toISOString();

        /*
        |--------------------------------------------------------------------------
        | Firestore + FCM ke DRIVER
        |--------------------------------------------------------------------------
        | Dipanggil lazy di dalam try/catch agar FIREBASE_CREDENTIALS yang belum
        | diset tidak menggagalkan proses approve.
        */
        try {
            $fcm = app(\App\Services\FcmService::class);
            $fs  = app(\App\Services\FirestoreService::class);

            $report = $booking->damageReport;

            if ($report && $report->driver) {
                $plate = $report?->vehicle?->plate_number ?? '-';

                $fs->pushUserNotification((int) $report->driver->id, [
                    'title' => 'Booking Servis Disetujui',
                    'body'  => 'Jadwal servis untuk kendaraan ' . $plate . ' sudah ditetapkan.',
                    'type'  => 'service_booking',
                    'role'  => 'driver',
                    'data'  => [
                        'report_id' => (int) $report->id,
                        'booking_id' => (int) $booking->id,
                        'status' => (string) $booking->status,
                        'preferred_at' => (string) ($preferredIso ?? ''),
                        'scheduled_at' => (string) ($scheduledIso ?? ''),
                        'estimated_finish_at' => (string) ($finishIso ?? ''),
                    ],
                ]);

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
                        'preferred_at' => (string) ($preferredIso ?? ''),
                        'scheduled_at' => (string) ($scheduledIso ?? ''),
                        'estimated_finish_at' => (string) ($finishIso ?? ''),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal kirim notifikasi approve booking ke driver', [
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
            NodeEventPublisher::publish('service_booking.approved', [
                'booking_id' => (int) $booking->id,
                'damage_report_id' => (int) $booking->damage_report_id,
                'driver_id' => (int) $booking->driver_id,
                'vehicle_id' => (int) $booking->vehicle_id,
                'technician_id' => $booking->technician_id ? (int) $booking->technician_id : null,
                'status' => (string) $booking->status,
                'priority' => (string) ($booking->priority ?? 'medium'),
                'preferred_at' => $preferredIso,
                'scheduled_at' => $scheduledIso,
                'estimated_finish_at' => $finishIso,
                'updated_at' => $updatedIso,
            ], ['admin', 'technician', 'driver']);
        } catch (\Throwable $e) {
            Log::warning('Gagal publish node event service_booking.approved', [
                'booking_id' => $booking->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Booking di-approve',
            'data' => $booking,
        ]);
    }

    /**
     * Reschedule booking
     */
    public function reschedule(Request $request, ServiceBooking $booking)
    {
        $request->validate([
            'scheduled_at' => 'required|date',
            'estimated_finish_at' => 'nullable|date|after_or_equal:scheduled_at',
            'technician_id' => 'nullable|exists:users,id',
            'priority' => 'nullable|string|in:low,medium,high,critical',
            'note_admin' => 'nullable|string|max:1000',
        ]);

        if (!in_array($booking->status, ['approved', 'requested', 'rescheduled'], true)) {
            return response()->json([
                'message' => 'Booking tidak bisa di-reschedule.',
            ], 422);
        }

        $booking->update([
            'status' => 'rescheduled',
            'scheduled_at' => $request->scheduled_at,
            'estimated_finish_at' => $request->estimated_finish_at,
            'technician_id' => $request->technician_id ?? $booking->technician_id,
            'priority' => $request->priority ?? $booking->priority ?? 'medium',
            'note_admin' => $request->note_admin,
        ]);

        $booking->refresh()->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        $scheduledIso = optional($booking->scheduled_at)->toISOString();
        $finishIso    = optional($booking->estimated_finish_at)->toISOString();
        $preferredIso = optional($booking->preferred_at)->toISOString();
        $updatedIso   = optional($booking->updated_at)->toISOString();

        /*
        |--------------------------------------------------------------------------
        | Firestore + FCM ke DRIVER
        |--------------------------------------------------------------------------
        */
        try {
            $fcm = app(\App\Services\FcmService::class);
            $fs  = app(\App\Services\FirestoreService::class);

            $report = $booking->damageReport;

            if ($report && $report->driver) {
                $plate = $report?->vehicle?->plate_number ?? '-';

                $fs->pushUserNotification((int) $report->driver->id, [
                    'title' => 'Jadwal Booking Diubah',
                    'body'  => 'Jadwal servis kendaraan ' . $plate . ' telah diubah.',
                    'type'  => 'service_booking',
                    'role'  => 'driver',
                    'data'  => [
                        'report_id' => (int) $report->id,
                        'booking_id' => (int) $booking->id,
                        'status' => (string) $booking->status,
                        'preferred_at' => (string) ($preferredIso ?? ''),
                        'scheduled_at' => (string) ($scheduledIso ?? ''),
                        'estimated_finish_at' => (string) ($finishIso ?? ''),
                    ],
                ]);

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
                        'estimated_finish_at' => (string) ($finishIso ?? ''),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal kirim notifikasi reschedule booking ke driver', [
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
            NodeEventPublisher::publish('service_booking.rescheduled', [
                'booking_id' => (int) $booking->id,
                'damage_report_id' => (int) $booking->damage_report_id,
                'driver_id' => (int) $booking->driver_id,
                'vehicle_id' => (int) $booking->vehicle_id,
                'technician_id' => $booking->technician_id ? (int) $booking->technician_id : null,
                'status' => (string) $booking->status,
                'priority' => (string) ($booking->priority ?? 'medium'),
                'preferred_at' => $preferredIso,
                'scheduled_at' => $scheduledIso,
                'estimated_finish_at' => $finishIso,
                'updated_at' => $updatedIso,
            ], ['admin', 'technician', 'driver']);
        } catch (\Throwable $e) {
            Log::warning('Gagal publish node event service_booking.rescheduled', [
                'booking_id' => $booking->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Booking di-reschedule',
            'data' => $booking,
        ]);
    }

    /**
     * Cancel booking
     */
    public function cancel(Request $request, ServiceBooking $booking)
    {
        $request->validate([
            'note_admin' => 'nullable|string|max:1000',
        ]);

        $booking->update([
            'status' => 'canceled',
            'note_admin' => $request->note_admin,
        ]);

        $booking->refresh()->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        $preferredIso = optional($booking->preferred_at)->toISOString();
        $scheduledIso = optional($booking->scheduled_at)->toISOString();
        $updatedIso   = optional($booking->updated_at)->toISOString();

        /*
        |--------------------------------------------------------------------------
        | Firestore + FCM ke DRIVER
        |--------------------------------------------------------------------------
        */
        try {
            $fcm = app(\App\Services\FcmService::class);
            $fs  = app(\App\Services\FirestoreService::class);

            $report = $booking->damageReport;

            if ($report && $report->driver) {
                $plate = $report?->vehicle?->plate_number ?? '-';

                $fs->pushUserNotification((int) $report->driver->id, [
                    'title' => 'Booking Dibatalkan',
                    'body'  => 'Booking servis kendaraan ' . $plate . ' dibatalkan admin.',
                    'type'  => 'service_booking',
                    'role'  => 'driver',
                    'data'  => [
                        'report_id' => (int) $report->id,
                        'booking_id' => (int) $booking->id,
                        'status' => (string) $booking->status,
                        'preferred_at' => (string) ($preferredIso ?? ''),
                        'scheduled_at' => (string) ($scheduledIso ?? ''),
                    ],
                ]);

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
                        'scheduled_at' => (string) ($scheduledIso ?? ''),
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal kirim notifikasi cancel booking ke driver', [
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
            NodeEventPublisher::publish('service_booking.canceled', [
                'booking_id' => (int) $booking->id,
                'damage_report_id' => (int) $booking->damage_report_id,
                'driver_id' => (int) $booking->driver_id,
                'vehicle_id' => (int) $booking->vehicle_id,
                'technician_id' => $booking->technician_id ? (int) $booking->technician_id : null,
                'status' => (string) $booking->status,
                'priority' => (string) ($booking->priority ?? 'medium'),
                'preferred_at' => $preferredIso,
                'scheduled_at' => $scheduledIso,
                'updated_at' => $updatedIso,
            ], ['admin', 'technician', 'driver']);
        } catch (\Throwable $e) {
            Log::warning('Gagal publish node event service_booking.canceled', [
                'booking_id' => $booking->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Booking dibatalkan',
            'data' => $booking,
        ]);
    }
}