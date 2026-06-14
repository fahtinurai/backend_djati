<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use App\Models\ServiceBooking;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\NodeEventPublisher;

class ServiceBookingController extends Controller
{
    /**
     * Driver melihat semua booking service miliknya.
     *
     * GET /api/driver/bookings
     *
     * PENTING:
     * Endpoint ini harus mengirim informasi dari teknisi juga:
     * - started_at
     * - completed_at
     * - note_technician
     * - final_hour_meter/current_hour_meter/latest_hour_meter
     * - total_repair_time
     * - total_operational_time
     * - failure_count
     * - actual_operating_hours
     * - breakdown_hours
     * - mttr
     * - mtbf
     * - ma
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
            ->get()
            ->map(fn ($booking) => $this->normalizeBookingForDriver($booking))
            ->values();

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
            'note_driver' => 'nullable|string|max:1000',
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
            $existing->load([
                'damageReport.vehicle',
                'damageReport.driver',
                'vehicle',
                'driver',
                'technician',
            ]);

            return response()->json([
                'message' => 'Booking sudah dijadwalkan admin, tidak bisa mengajukan ulang. Silakan hubungi admin jika perlu ubah jadwal.',
                'data' => $this->normalizeBookingForDriver($existing),
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
            $this->safeSet($booking, 'damage_report_id', $damageReport->id);
            $this->safeSet($booking, 'requested_at', now());
        }

        $this->safeSet($booking, 'driver_id', $driver->id);
        $this->safeSet($booking, 'vehicle_id', $damageReport->vehicle_id);
        $this->safeSet($booking, 'preferred_at', $request->preferred_at);
        $this->safeSet($booking, 'status', 'requested');
        $this->safeSet($booking, 'priority', $booking->priority ?: 'medium');

        $noteDriver = $request->note_driver
            ?? ($request->preferred_at ? ('Preferensi jadwal: ' . $request->preferred_at) : null);

        $this->safeSet($booking, 'note_driver', $noteDriver);

        /*
        |--------------------------------------------------------------------------
        | Reset saat driver mengajukan booking.
        |--------------------------------------------------------------------------
        | Driver hanya boleh membuat booking requested.
        | Teknisi dan jadwal hanya boleh diisi oleh admin saat approve.
        | Data hasil teknisi juga dibersihkan agar booking requested tidak membawa
        | data maintenance lama.
        */
        $this->resetBookingForDriverRequest($booking);

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
                    'type' => 'service_booking',
                    'role' => 'admin',
                    'report_id' => (string) $damageReport->id,
                    'booking_id' => (string) $booking->id,
                    'driver_id' => (string) $driver->id,
                    'vehicle_id' => (string) $damageReport->vehicle_id,
                    'status' => (string) $booking->status,
                    'preferred_at' => (string) ($this->safeIso($booking->preferred_at) ?? ''),
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
                'booking_id' => (int) $booking->id,
                'damage_report_id' => (int) $booking->damage_report_id,
                'driver_id' => (int) $booking->driver_id,
                'vehicle_id' => (int) $booking->vehicle_id,
                'equipment_name' => $booking->vehicle?->equipment_name
                    ?? $damageReport?->vehicle?->equipment_name,
                'plate_number' => $booking->vehicle?->plate_number
                    ?? $damageReport?->vehicle?->plate_number,
                'status' => (string) $booking->status,
                'priority' => (string) ($booking->priority ?? 'medium'),
                'technician_id' => null,
                'preferred_at' => $this->safeIso($booking->preferred_at),
                'scheduled_at' => null,
                'estimated_finish_at' => null,
                'requested_at' => $this->safeIso($booking->requested_at),
                'created_at' => $this->safeIso($booking->created_at),
                'updated_at' => $this->safeIso($booking->updated_at),
            ], ['admin']);
        } catch (\Throwable $e) {
            Log::warning('Gagal publish node event service_booking.requested', [
                'booking_id' => $booking->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Booking berhasil diajukan',
            'data' => $this->normalizeBookingForDriver($booking),
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
            'data' => $booking ? $this->normalizeBookingForDriver($booking) : null,
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
        $this->safeSet($booking, 'status', 'canceled');
        $this->safeSet($booking, 'technician_id', null);
        $this->safeSet($booking, 'scheduled_at', null);
        $this->safeSet($booking, 'estimated_finish_at', null);
        $this->safeSet($booking, 'note_admin', null);
        $this->safeSet($booking, 'started_at', null);
        $this->safeSet($booking, 'completed_at', null);
        $this->safeSet($booking, 'note_technician', null);

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
                    'driver_id' => (string) $driver->id,
                    'vehicle_id' => (string) $report->vehicle_id,
                    'status' => (string) $booking->status,
                    'preferred_at' => (string) ($this->safeIso($booking->preferred_at) ?? ''),
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
                'booking_id' => (int) $booking->id,
                'damage_report_id' => (int) $booking->damage_report_id,
                'driver_id' => (int) $booking->driver_id,
                'vehicle_id' => (int) $booking->vehicle_id,
                'equipment_name' => $booking->vehicle?->equipment_name
                    ?? $report?->vehicle?->equipment_name,
                'plate_number' => $booking->vehicle?->plate_number
                    ?? $report?->vehicle?->plate_number,
                'status' => (string) $booking->status,
                'priority' => (string) ($booking->priority ?? 'medium'),
                'technician_id' => null,
                'preferred_at' => $this->safeIso($booking->preferred_at),
                'scheduled_at' => null,
                'estimated_finish_at' => null,
                'requested_at' => $this->safeIso($booking->requested_at),
                'created_at' => $this->safeIso($booking->created_at),
                'updated_at' => $this->safeIso($booking->updated_at),
            ], ['admin']);
        } catch (\Throwable $e) {
            Log::warning('Gagal publish node event service_booking.canceled', [
                'booking_id' => $booking->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Booking dibatalkan',
            'data' => $this->normalizeBookingForDriver($booking),
        ]);
    }

    /**
     * Normalisasi booking agar driver menerima informasi teknisi lengkap.
     */
    private function normalizeBookingForDriver(ServiceBooking $booking)
    {
        $vehicle = $booking->vehicle ?? $booking->damageReport?->vehicle;
        $report = $booking->damageReport;

        /*
        |--------------------------------------------------------------------------
        | Alias field maintenance di level booking
        |--------------------------------------------------------------------------
        */
        $finalHourMeter = $booking->final_hour_meter
            ?? $booking->current_hour_meter
            ?? $booking->latest_hour_meter
            ?? $vehicle?->current_hour_meter
            ?? $vehicle?->latest_hour_meter
            ?? null;

        $currentMa = $booking->ma
            ?? $booking->current_ma
            ?? $vehicle?->current_ma
            ?? $vehicle?->ma
            ?? $vehicle?->mechanical_availability
            ?? null;

        $booking->setAttribute('final_hour_meter', $finalHourMeter);
        $booking->setAttribute('current_hour_meter', $finalHourMeter);
        $booking->setAttribute('latest_hour_meter', $finalHourMeter);

        $booking->setAttribute('current_ma', $currentMa);
        $booking->setAttribute('mechanical_availability', $currentMa);

        $booking->setAttribute('total_repair_time', $booking->total_repair_time ?? $booking->repair_time ?? $booking->repair_time_hours ?? null);
        $booking->setAttribute('total_operational_time', $booking->total_operational_time ?? $booking->operational_time ?? $booking->operational_time_hours ?? null);
        $booking->setAttribute('failure_count', $booking->failure_count ?? $booking->number_of_failures ?? $booking->failures ?? null);
        $booking->setAttribute('actual_operating_hours', $booking->actual_operating_hours ?? $booking->actual_operation_hours ?? null);
        $booking->setAttribute('breakdown_hours', $booking->breakdown_hours ?? $booking->breakdown_time ?? null);

        /*
        |--------------------------------------------------------------------------
        | Alias vehicle agar Flutter driver mudah membaca current condition.
        |--------------------------------------------------------------------------
        */
        if ($vehicle) {
            $vehicle->setAttribute('current_hour_meter', $vehicle->current_hour_meter ?? $vehicle->latest_hour_meter ?? $finalHourMeter);
            $vehicle->setAttribute('latest_hour_meter', $vehicle->latest_hour_meter ?? $vehicle->current_hour_meter ?? $finalHourMeter);
            $vehicle->setAttribute('current_ma', $vehicle->current_ma ?? $vehicle->ma ?? $vehicle->mechanical_availability ?? $currentMa);
            $vehicle->setAttribute('mechanical_availability', $vehicle->mechanical_availability ?? $vehicle->current_ma ?? $currentMa);
        }

        /*
        |--------------------------------------------------------------------------
        | Alias damage report agar service lama tetap aman.
        |--------------------------------------------------------------------------
        */
        if ($report) {
            $report->setAttribute('booking_id', $booking->id);
            $report->setAttribute('service_booking_id', $booking->id);
            $report->setAttribute('booking_status', $booking->status);
            $report->setAttribute('computed_status', $this->computedStatusFromBooking($booking));
        }

        return $booking;
    }

    /**
     * Status gabungan untuk driver.
     */
    private function computedStatusFromBooking(ServiceBooking $booking): string
    {
        $status = strtolower((string) $booking->status);

        if (in_array($status, ['requested', 'pending', 'reported', 'menunggu'], true)) {
            return 'requested';
        }

        if (in_array($status, ['approved', 'scheduled'], true)) {
            return 'scheduled';
        }

        if ($status === 'rescheduled') {
            return 'rescheduled';
        }

        if (in_array($status, ['in_progress', 'ongoing', 'proses'], true)) {
            return 'in_progress';
        }

        if (in_array($status, ['completed', 'finished', 'selesai'], true)) {
            return 'completed';
        }

        if (in_array($status, ['canceled', 'cancelled', 'dibatalkan'], true)) {
            return 'canceled';
        }

        if (in_array($status, ['rejected', 'ditolak'], true)) {
            return 'rejected';
        }

        return $status ?: 'requested';
    }

    /**
     * Reset field saat driver membuat booking requested.
     */
    private function resetBookingForDriverRequest(ServiceBooking $booking): void
    {
        $fieldsToNull = [
            'technician_id',
            'scheduled_at',
            'estimated_finish_at',
            'note_admin',
            'started_at',
            'completed_at',
            'note_technician',
            'final_hour_meter',
            'current_hour_meter',
            'latest_hour_meter',
            'total_repair_time',
            'repair_time',
            'repair_time_hours',
            'total_operational_time',
            'operational_time',
            'operational_time_hours',
            'failure_count',
            'number_of_failures',
            'failures',
            'actual_operating_hours',
            'actual_operation_hours',
            'breakdown_hours',
            'breakdown_time',
            'mttr',
            'mtbf',
            'ma',
        ];

        foreach ($fieldsToNull as $field) {
            $this->safeSet($booking, $field, null);
        }
    }

    /**
     * Set kolom hanya jika kolomnya ada di database.
     */
    private function safeSet(ServiceBooking $booking, string $column, mixed $value): void
    {
        if ($this->hasColumnOnTable($booking->getTable(), $column)) {
            $booking->{$column} = $value;
        }
    }


    /**
     * Format tanggal aman ke ISO string.
     */
    private function safeIso($value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            if ($value instanceof \Carbon\CarbonInterface) {
                return $value->toISOString();
            }

            return \Carbon\Carbon::parse($value)->toISOString();
        } catch (\Throwable $e) {
            return (string) $value;
        }
    }

    /**
     * Helper aman cek kolom.
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
