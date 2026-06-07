<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use App\Models\ServiceBooking;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\NodeEventPublisher;

class ServiceBookingController extends Controller
{
    /**
     * Driver melihat semua booking service miliknya.
     *
     * GET /api/driver/bookings
     */
    public function index(Request $request)
    {
        $driver = $request->user();

        $bookings = ServiceBooking::with([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ])
            ->where('driver_id', $driver->id)
            ->latest()
            ->get();

        return response()->json([
            'data' => $bookings,
        ]);
    }

    /**
     * Driver request booking service berdasarkan DamageReport.
     *
     * POST /api/driver/damage-reports/{damageReport}/booking
     */
    public function store(Request $request, DamageReport $damageReport)
    {
        $driver = $request->user();

        if ((int) $damageReport->driver_id !== (int) $driver->id) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        $assignment = VehicleAssignment::with('vehicle')
            ->where('driver_id', $driver->id)
            ->where('vehicle_id', $damageReport->vehicle_id)
            ->first();

        if (!$assignment || !$assignment->vehicle) {
            return response()->json([
                'message' => 'Kendaraan pada laporan ini tidak terhubung dengan driver.',
            ], 403);
        }

        $request->validate([
            'preferred_at' => 'nullable|date',
            'note_driver'  => 'nullable|string|max:1000',
        ]);

        $damageReport->load('latestTechnicianResponse');

        $lastStatus = optional($damageReport->latestTechnicianResponse)->status ?? 'menunggu';

        if (in_array($lastStatus, ['selesai', 'finished', 'completed'], true)) {
            return response()->json([
                'message' => 'Laporan sudah selesai.',
            ], 422);
        }

        $existing = ServiceBooking::where('damage_report_id', $damageReport->id)
            ->latest()
            ->first();

        if ($existing && in_array($existing->status, [
            'approved',
            'scheduled',
            'rescheduled',
            'in_progress',
            'completed',
            'finished',
            'selesai',
        ], true)) {
            return response()->json([
                'message' => 'Booking sudah dijadwalkan admin, tidak bisa mengajukan ulang. Silakan hubungi admin jika perlu ubah jadwal.',
                'data' => $existing->load([
                    'damageReport.vehicle',
                    'damageReport.driver',
                    'vehicle',
                    'driver',
                    'technician',
                ]),
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Penting:
        |--------------------------------------------------------------------------
        | Kalau booking sebelumnya canceled, JANGAN dipakai ulang.
        | Buat row booking baru supaya tidak membawa technician_id / jadwal lama.
        |
        | Kalau booking terakhir masih requested, cukup update booking itu.
        */
        if ($existing && $existing->status === 'requested') {
            $booking = $existing;
        } else {
            $booking = new ServiceBooking();
            $booking->damage_report_id = $damageReport->id;
            $booking->requested_at = now();
        }

        $booking->driver_id = $driver->id;
        $booking->vehicle_id = $damageReport->vehicle_id;
        $booking->preferred_at = $request->preferred_at;
        $booking->status = 'requested';
        $booking->priority = $booking->priority ?: 'medium';

        $booking->note_driver = $request->note_driver
            ?? ($request->preferred_at ? ('Preferensi jadwal: ' . $request->preferred_at) : null);

        /*
        |--------------------------------------------------------------------------
        | Reset total saat driver mengajukan booking.
        |--------------------------------------------------------------------------
        | Driver hanya boleh membuat booking requested.
        | Teknisi dan jadwal hanya boleh diisi oleh admin saat approve.
        */
        $booking->technician_id = null;
        $booking->scheduled_at = null;
        $booking->estimated_finish_at = null;
        $booking->note_admin = null;
        $booking->started_at = null;
        $booking->completed_at = null;
        $booking->note_technician = null;

        $booking->save();

        $booking->refresh();

        $booking->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        /*
        |--------------------------------------------------------------------------
        | FCM ke admin
        |--------------------------------------------------------------------------
        */
        try {
            $fcm = app(\App\Services\FcmService::class);

            $damageReport->loadMissing('vehicle');

            $plate = $damageReport?->vehicle?->plate_number ?? '-';

            $fcm->sendToRole(
                'admin',
                'Booking Servis Baru',
                'Driver mengajukan booking servis untuk kendaraan ' . $plate,
                [
                    'type'         => 'service_booking',
                    'role'         => 'admin',
                    'report_id'    => (string) $damageReport->id,
                    'booking_id'   => (string) $booking->id,
                    'driver_id'    => (string) $driver->id,
                    'vehicle_id'   => (string) $damageReport->vehicle_id,
                    'status'       => (string) $booking->status,
                    'preferred_at' => (string) (optional($booking->preferred_at)->toISOString() ?? ''),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Gagal kirim FCM booking servis baru ke admin', [
                'booking_id' => $booking->id ?? null,
                'damage_report_id' => $damageReport->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Node event ke admin
        |--------------------------------------------------------------------------
        */
        try {
            NodeEventPublisher::publish('service_booking.requested', [
                'booking_id'       => (int) $booking->id,
                'damage_report_id' => (int) $booking->damage_report_id,
                'driver_id'        => (int) $booking->driver_id,
                'vehicle_id'       => (int) $booking->vehicle_id,
                'equipment_name'   => $booking->vehicle?->equipment_name
                    ?? $damageReport?->vehicle?->equipment_name,
                'plate_number'     => $booking->vehicle?->plate_number
                    ?? $damageReport?->vehicle?->plate_number,
                'status'           => (string) $booking->status,
                'priority'         => (string) ($booking->priority ?? 'medium'),
                'technician_id'    => null,
                'preferred_at'     => optional($booking->preferred_at)->toISOString(),
                'scheduled_at'     => null,
                'estimated_finish_at' => null,
                'requested_at'     => optional($booking->requested_at)->toISOString(),
                'created_at'       => optional($booking->created_at)->toISOString(),
                'updated_at'       => optional($booking->updated_at)->toISOString(),
            ], ['admin']);
        } catch (\Throwable $e) {
            Log::warning('Gagal publish node event service_booking.requested', [
                'booking_id' => $booking->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Booking berhasil diajukan',
            'data'    => $booking,
        ], 201);
    }

    /**
     * Driver melihat booking dari damage report miliknya.
     *
     * GET /api/driver/damage-reports/{damageReport}/booking
     */
    public function show(Request $request, DamageReport $damageReport)
    {
        $driver = $request->user();

        if ((int) $damageReport->driver_id !== (int) $driver->id) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        $assignment = VehicleAssignment::where('driver_id', $driver->id)
            ->where('vehicle_id', $damageReport->vehicle_id)
            ->first();

        if (!$assignment) {
            return response()->json([
                'message' => 'Kendaraan pada laporan ini tidak terhubung dengan driver.',
            ], 403);
        }

        $booking = ServiceBooking::with([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ])
            ->where('damage_report_id', $damageReport->id)
            ->latest()
            ->first();

        return response()->json([
            'data' => $booking,
        ]);
    }

    /**
     * Driver membatalkan booking service.
     *
     * POST /api/driver/bookings/{booking}/cancel
     */
    public function cancel(Request $request, ServiceBooking $booking)
    {
        $driver = $request->user();

        $booking->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        $report = $booking->damageReport;

        if (!$report || (int) $report->driver_id !== (int) $driver->id) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        $assignment = VehicleAssignment::where('driver_id', $driver->id)
            ->where('vehicle_id', $report->vehicle_id)
            ->first();

        if (!$assignment) {
            return response()->json([
                'message' => 'Kendaraan pada booking ini tidak terhubung dengan driver.',
            ], 403);
        }

        if (!in_array($booking->status, ['requested', 'approved', 'scheduled', 'rescheduled'], true)) {
            return response()->json([
                'message' => 'Booking tidak bisa dibatalkan.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Penting:
        |--------------------------------------------------------------------------
        | Saat cancel, technician_id dan jadwal harus dibersihkan.
        | Supaya booking canceled tidak tetap muncul di halaman teknisi.
        */
        $booking->update([
            'status' => 'canceled',
            'technician_id' => null,
            'scheduled_at' => null,
            'estimated_finish_at' => null,
            'note_admin' => null,
            'started_at' => null,
            'completed_at' => null,
            'note_technician' => null,
        ]);

        $booking->refresh();

        $booking->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        /*
        |--------------------------------------------------------------------------
        | FCM ke admin
        |--------------------------------------------------------------------------
        */
        try {
            $fcm = app(\App\Services\FcmService::class);

            $plate = $report?->vehicle?->plate_number ?? '-';

            $fcm->sendToRole(
                'admin',
                'Booking Dibatalkan',
                'Driver membatalkan booking servis kendaraan ' . $plate,
                [
                    'type'         => 'service_booking',
                    'role'         => 'admin',
                    'report_id'    => (string) $report->id,
                    'booking_id'   => (string) $booking->id,
                    'driver_id'    => (string) $driver->id,
                    'vehicle_id'   => (string) $report->vehicle_id,
                    'status'       => (string) $booking->status,
                    'preferred_at' => (string) (optional($booking->preferred_at)->toISOString() ?? ''),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Gagal kirim FCM cancel booking ke admin', [
                'booking_id' => $booking->id ?? null,
                'damage_report_id' => $report->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Node event ke admin
        |--------------------------------------------------------------------------
        */
        try {
            NodeEventPublisher::publish('service_booking.canceled', [
                'booking_id'       => (int) $booking->id,
                'damage_report_id' => (int) $booking->damage_report_id,
                'driver_id'        => (int) $booking->driver_id,
                'vehicle_id'       => (int) $booking->vehicle_id,
                'equipment_name'   => $booking->vehicle?->equipment_name
                    ?? $report?->vehicle?->equipment_name,
                'plate_number'     => $booking->vehicle?->plate_number
                    ?? $report?->vehicle?->plate_number,
                'status'           => (string) $booking->status,
                'priority'         => (string) ($booking->priority ?? 'medium'),
                'technician_id'    => null,
                'preferred_at'     => optional($booking->preferred_at)->toISOString(),
                'scheduled_at'     => null,
                'estimated_finish_at' => null,
                'requested_at'     => optional($booking->requested_at)->toISOString(),
                'created_at'       => optional($booking->created_at)->toISOString(),
                'updated_at'       => optional($booking->updated_at)->toISOString(),
            ], ['admin']);
        } catch (\Throwable $e) {
            Log::warning('Gagal publish node event service_booking.canceled', [
                'booking_id' => $booking->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Booking dibatalkan',
            'data'    => $booking,
        ]);
    }
}