<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use App\Models\ServiceBooking;
use Illuminate\Http\Request;
use App\Services\FcmService;
use App\Services\NodeEventPublisher;

class ServiceBookingController extends Controller
{
    /**
     * Driver request booking (melekat ke DamageReport)
     * Driver hanya "mengajukan", admin yang menentukan jadwal final.
     */
    public function store(Request $request, DamageReport $damageReport, FcmService $fcm)
    {
        $driver = $request->user();

        // pastikan report milik driver
        if ((int) $damageReport->driver_id !== (int) $driver->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'preferred_at' => 'nullable|date',
            'note_driver'  => 'nullable|string',
        ]);

        // jangan booking kalau sudah selesai
        $damageReport->load('latestTechnicianResponse');
        $lastStatus = optional($damageReport->latestTechnicianResponse)->status ?? 'menunggu';
        if ($lastStatus === 'selesai') {
            return response()->json(['message' => 'Laporan sudah selesai.'], 422);
        }

        // ambil booking existing (kalau ada)
        $existing = ServiceBooking::where('damage_report_id', $damageReport->id)->first();

        // kalau sudah dijadwalkan admin, jangan reset jadi requested lagi
        if ($existing && in_array($existing->status, ['approved', 'rescheduled'], true)) {
            return response()->json([
                'message' => 'Booking sudah dijadwalkan admin, tidak bisa mengajukan ulang. Silakan hubungi admin jika perlu ubah jadwal.'
            ], 422);
        }

        // buat baru atau pakai existing yang belum dijadwalkan
        $booking = $existing ?: new ServiceBooking();
        if (!$booking->exists) {
            $booking->damage_report_id = $damageReport->id;
            $booking->requested_at = now();
            $booking->status = 'requested';
        }

        $booking->driver_id = $driver->id;
        $booking->vehicle_id = $damageReport->vehicle_id;

        // simpan preferensi driver sebagai DATETIME beneran (sinkron)
        $booking->preferred_at = $request->preferred_at;

        // note_driver tetap boleh otomatis
        $booking->note_driver = $request->note_driver
            ?? ($request->preferred_at ? ('Preferensi jadwal: ' . $request->preferred_at) : null);

        $booking->save();

        $booking->load(['damageReport.vehicle', 'damageReport.driver']);

        // Optional: notif ke admin (booking request)
        try {
            $damageReport->loadMissing('vehicle');
            $plate = $damageReport?->vehicle?->plate_number ?? '-';

            $fcm->sendToRole(
                'admin',
                'Booking Servis Baru',
                'Driver mengajukan booking servis untuk kendaraan ' . $plate,
                [
                    'type' => 'service_booking',
                    'role' => 'admin',
                    'report_id' => (string) $damageReport->id,
                    'booking_id' => (string) $booking->id,
                    'status' => (string) $booking->status,
                    // opsional (biar admin UI bisa tampilkan cepat)
                    'preferred_at' => (string) (optional($booking->preferred_at)->toISOString() ?? ''),
                ]
            );
        } catch (\Throwable $e) {}

        // node event (opsional)
        try {
            NodeEventPublisher::publish('service_booking.requested', [
                'booking_id' => (int) $booking->id,
                'damage_report_id' => (int) $booking->damage_report_id,
                'status' => (string) $booking->status,
                'preferred_at' => optional($booking->preferred_at)->toISOString(),
                'requested_at' => optional($booking->requested_at)->toISOString(),
                'created_at' => optional($booking->created_at)->toISOString(),
                'updated_at' => optional($booking->updated_at)->toISOString(),
            ], ['admin']);
        } catch (\Throwable $e) {}

        return response()->json([
            'message' => 'Booking berhasil diajukan',
            'data' => $booking,
        ], 201);
    }

    public function show(Request $request, DamageReport $damageReport)
    {
        $driver = $request->user();

        if ((int) $damageReport->driver_id !== (int) $driver->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $damageReport->load(['booking']);
        return response()->json($damageReport->booking);
    }

    public function cancel(Request $request, ServiceBooking $booking, FcmService $fcm)
    {
        $driver = $request->user();

        // cek kepemilikan via damage report
        $booking->load(['damageReport.vehicle', 'damageReport.driver']);
        $report = $booking->damageReport;

        if (!$report || (int) $report->driver_id !== (int) $driver->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!in_array($booking->status, ['requested', 'approved', 'rescheduled'], true)) {
            return response()->json(['message' => 'Booking tidak bisa dibatalkan.'], 422);
        }

        $booking->update(['status' => 'canceled']);

        // notif admin (opsional)
        try {
            $plate = $report?->vehicle?->plate_number ?? '-';

            $fcm->sendToRole(
                'admin',
                'Booking Dibatalkan',
                'Driver membatalkan booking servis kendaraan ' . $plate,
                [
                    'type' => 'service_booking',
                    'role' => 'admin',
                    'report_id' => (string) $report->id,
                    'booking_id' => (string) $booking->id,
                    'status' => (string) $booking->status,
                    'preferred_at' => (string) (optional($booking->preferred_at)->toISOString() ?? ''),
                ]
            );
        } catch (\Throwable $e) {}

        return response()->json([
            'message' => 'Booking dibatalkan',
            'data' => $booking,
        ]);
    }
}
