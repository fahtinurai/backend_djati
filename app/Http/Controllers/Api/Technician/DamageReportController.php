<?php

namespace App\Http\Controllers\Api\Technician;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use App\Models\ServiceBooking;
use App\Models\TechnicianResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use App\Services\FcmService;
use App\Services\NodeEventPublisher;

class DamageReportController extends Controller
{
    /**
     * List laporan kerusakan untuk teknisi.
     *
     * PENTING:
     * Teknisi TIDAK boleh mengambil semua DamageReport.
     * Teknisi hanya boleh melihat booking yang:
     * - technician_id = teknisi login
     * - status sudah approved/scheduled/rescheduled/in_progress
     * - bukan requested
     * - bukan canceled
     */
    public function index(Request $request)
    {
        $technician = $request->user();

        $rawStatus = $request->input('status');
        $status = $rawStatus
            ? $this->normalizeStatus($rawStatus)
            : null;

        $includeDone = $request->boolean('include_done');

        $q = ServiceBooking::query()
            ->with([
                'damageReport.vehicle',
                'damageReport.driver',
                'damageReport.latestTechnicianResponse.technician',
                'damageReport.technicianResponses.technician',
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
            ->latest();

        if (!empty($status)) {
            if ($status === 'menunggu') {
                $q->whereIn('status', [
                    'approved',
                    'scheduled',
                    'rescheduled',
                ])->whereDoesntHave('damageReport.latestTechnicianResponse');
            } elseif ($status === 'proses') {
                $q->where(function ($query) {
                    $query->where('status', 'in_progress')
                        ->orWhereHas('damageReport.latestTechnicianResponse', function ($r) {
                            $r->where('status', 'proses');
                        });
                });
            } elseif ($status === 'butuh_followup_admin') {
                $q->whereHas('damageReport.latestTechnicianResponse', function ($r) {
                    $r->where('status', 'butuh_followup_admin');
                });
            } elseif ($status === 'selesai') {
                $q->where(function ($query) {
                    $query->whereIn('status', [
                        'completed',
                        'finished',
                        'selesai',
                    ])->orWhereHas('damageReport.latestTechnicianResponse', function ($r) {
                        $r->where('status', 'selesai');
                    });
                });
            } elseif ($status === 'fatal') {
                $q->whereHas('damageReport.latestTechnicianResponse', function ($r) {
                    $r->where('status', 'fatal');
                });
            }

            $bookings = $q->get();

            return response()->json(
                $bookings
                    ->map(fn ($booking) => $this->bookingToDamageReportPayload($booking))
                    ->filter()
                    ->values()
            );
        }

        if (!$includeDone) {
            $q->whereNotIn('status', [
                'completed',
                'finished',
                'selesai',
            ])->where(function ($query) {
                $query->whereDoesntHave('damageReport.latestTechnicianResponse')
                    ->orWhereHas('damageReport.latestTechnicianResponse', function ($r) {
                        $r->where('status', '!=', 'selesai');
                    });
            });
        }

        $bookings = $q->get();

        return response()->json(
            $bookings
                ->map(fn ($booking) => $this->bookingToDamageReportPayload($booking))
                ->filter()
                ->values()
        );
    }

    /**
     * Detail laporan kerusakan.
     *
     * Teknisi hanya boleh membuka laporan yang sudah di-assign admin.
     */
    public function show(Request $request, DamageReport $damageReport)
    {
        $technician = $request->user();

        $booking = $this->getAssignedBooking($damageReport, $technician->id);

        if (!$booking) {
            return response()->json([
                'message' => 'Laporan ini belum ditugaskan ke teknisi login.',
            ], 403);
        }

        $damageReport->load([
            'vehicle',
            'driver',
            'technicianResponses.technician',
            'latestTechnicianResponse.technician',
        ]);

        $damageReport->setAttribute('latest_service_booking', $booking);
        $damageReport->setAttribute('service_booking', $booking);
        $damageReport->setAttribute('booking', $booking);
        $damageReport->setAttribute('booking_status', $booking->status);
        $damageReport->setAttribute('computed_status', $this->computedStatusFromBooking($booking, $damageReport));

        return response()->json($damageReport);
    }

    /**
     * Teknisi memberi respons / update status laporan kerusakan.
     */
    public function respond(Request $request, DamageReport $damageReport)
    {
        $technician = $request->user();

        $booking = $this->getAssignedBooking($damageReport, $technician->id);

        if (!$booking) {
            return response()->json([
                'message' => 'Laporan ini belum di-approve admin atau belum ditugaskan ke teknisi login.',
            ], 403);
        }

        Log::info('TECHNICIAN RESPOND MASUK', [
            'user_id' => optional($technician)->id,
            'damage_report_id' => $damageReport->id,
            'booking_id' => $booking->id,
            'request_all' => $request->all(),
        ]);

        $request->validate([
            'status' => 'required|string',
            'note'   => 'nullable|string',
            'mttr'   => 'nullable|numeric',
            'mtbf'   => 'nullable|numeric',
            'ma'     => 'nullable|numeric',
        ]);

        $status = $this->normalizeStatus($request->status);

        if (!in_array($status, ['proses', 'butuh_followup_admin', 'fatal', 'selesai'], true)) {
            return response()->json([
                'message' => 'Status tidak valid.',
                'status_dikirim' => $request->status,
                'status_normalized' => $status,
                'allowed_status' => [
                    'proses',
                    'butuh_followup_admin',
                    'fatal',
                    'selesai',
                ],
            ], 422);
        }

        $data = [
            'damage_id'     => $damageReport->id,
            'technician_id' => $technician->id,
            'status'        => $status,
            'note'          => $request->note,
        ];

        if (Schema::hasColumn('technician_responses', 'mttr')) {
            $data['mttr'] = $request->mttr;
        }

        if (Schema::hasColumn('technician_responses', 'mtbf')) {
            $data['mtbf'] = $request->mtbf;
        }

        if (Schema::hasColumn('technician_responses', 'ma')) {
            $data['ma'] = $request->ma;
        }

        $response = TechnicianResponse::create($data);

        if (Schema::hasColumn('damage_reports', 'status')) {
            $damageReport->update([
                'status' => $status,
            ]);
        }

        $this->syncBookingStatusFromTechnicianResponse($booking, $status, $request->note);

        $response->load('technician');

        $damageReport->load([
            'driver',
            'vehicle',
            'latestTechnicianResponse.technician',
            'technicianResponses.technician',
        ]);

        Log::info('TECHNICIAN RESPOND BERHASIL DISIMPAN', [
            'technician_response_id' => $response->id,
            'damage_id' => $response->damage_id,
            'technician_id' => $response->technician_id,
            'status' => $response->status,
            'booking_id' => $booking->id,
            'booking_status' => $booking->status,
        ]);

        try {
            $fcm = app(FcmService::class);

            if ($damageReport->driver) {
                $fcm->sendToUser(
                    $damageReport->driver,
                    'Update Laporan Kendaraan',
                    'Status laporan kamu: ' . $this->statusLabelForDriver($status),
                    [
                        'type'      => 'damage_report',
                        'role'      => 'driver',
                        'report_id' => (string) $damageReport->id,
                        'booking_id' => (string) $booking->id,
                        'status'    => (string) $status,
                    ]
                );
            }

            if ($status === 'butuh_followup_admin') {
                $plate = $damageReport->vehicle?->plate_number ?? '-';

                $fcm->sendToRole(
                    'admin',
                    'Butuh Follow-up Admin',
                    'Ada laporan butuh follow-up untuk kendaraan ' . $plate,
                    [
                        'type'      => 'damage_report',
                        'role'      => 'admin',
                        'report_id' => (string) $damageReport->id,
                        'booking_id' => (string) $booking->id,
                        'status'    => (string) $status,
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('FCM gagal / dilewati saat technician respond', [
                'message' => $e->getMessage(),
            ]);
        }

        try {
            NodeEventPublisher::publish('technician_response.created', [
                'technician_response_id' => $response->id,
                'damage_report_id'       => $damageReport->id,
                'booking_id'             => $booking->id,
                'technician_id'          => $technician->id,
                'technician_name'        => $technician->name ?? $technician->username ?? null,
                'vehicle_id'             => $damageReport->vehicle_id,
                'equipment_name'         => $damageReport->vehicle?->equipment_name,
                'plate_number'           => $damageReport->vehicle?->plate_number,
                'driver_id'              => $damageReport->driver_id,
                'driver_name'            => $damageReport->driver?->name ?? $damageReport->driver?->username,
                'status'                 => $response->status,
                'status_label'           => $this->statusLabelForDriver($response->status),
                'booking_status'         => $booking->status,
                'note'                   => $response->note,
                'mttr'                   => $response->mttr ?? null,
                'mtbf'                   => $response->mtbf ?? null,
                'ma'                     => $response->ma ?? null,
                'created_at'             => optional($response->created_at)
                    ->timezone('Asia/Jakarta')
                    ->format('Y-m-d H:i:s'),
            ], ['admin', 'driver']);
        } catch (\Throwable $e) {
            Log::warning('Node event technician_response.created gagal', [
                'message' => $e->getMessage(),
            ]);
        }

        if ($response->status === 'butuh_followup_admin') {
            try {
                NodeEventPublisher::publish('damage_report.followup_created', [
                    'damage_report_id'        => $damageReport->id,
                    'booking_id'              => $booking->id,
                    'status'                  => $response->status,
                    'technician_response_id'  => $response->id,
                    'technician_id'           => $technician->id,
                    'updated_at'              => now('Asia/Jakarta')->format('Y-m-d H:i:s'),
                ], ['admin']);
            } catch (\Throwable $e) {
                Log::warning('Node event damage_report.followup_created gagal', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message'  => 'Respons teknisi berhasil ditambahkan',
            'response' => $response,
            'damage_report_status' => $status,
            'booking_status' => $booking->status,
        ], 201);
    }

    /**
     * Update response milik teknisi yang sedang login.
     */
    public function updateResponse(Request $request, TechnicianResponse $technicianResponse)
    {
        $technician = $request->user();

        if ((int) $technicianResponse->technician_id !== (int) $technician->id) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        $technicianResponse->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'technician',
        ]);

        $damageReport = $technicianResponse->damageReport;

        if (!$damageReport) {
            return response()->json([
                'message' => 'Damage report tidak ditemukan.',
            ], 404);
        }

        $booking = $this->getAssignedBooking($damageReport, $technician->id);

        if (!$booking) {
            return response()->json([
                'message' => 'Booking tidak aktif atau tidak ditugaskan ke teknisi login.',
            ], 403);
        }

        $request->validate([
            'status' => 'sometimes|string',
            'note'   => 'nullable|string',
            'mttr'   => 'nullable|numeric',
            'mtbf'   => 'nullable|numeric',
            'ma'     => 'nullable|numeric',
        ]);

        $data = [];

        if ($request->filled('status')) {
            $status = $this->normalizeStatus($request->status);

            if (!in_array($status, ['proses', 'butuh_followup_admin', 'fatal', 'selesai'], true)) {
                return response()->json([
                    'message' => 'Status tidak valid.',
                    'status_dikirim' => $request->status,
                    'status_normalized' => $status,
                ], 422);
            }

            $data['status'] = $status;
        }

        if ($request->has('note')) {
            $data['note'] = $request->note;
        }

        if (Schema::hasColumn('technician_responses', 'mttr') && $request->has('mttr')) {
            $data['mttr'] = $request->mttr;
        }

        if (Schema::hasColumn('technician_responses', 'mtbf') && $request->has('mtbf')) {
            $data['mtbf'] = $request->mtbf;
        }

        if (Schema::hasColumn('technician_responses', 'ma') && $request->has('ma')) {
            $data['ma'] = $request->ma;
        }

        $technicianResponse->update($data);

        if (
            isset($data['status']) &&
            $technicianResponse->damageReport &&
            Schema::hasColumn('damage_reports', 'status')
        ) {
            $technicianResponse->damageReport->update([
                'status' => $data['status'],
            ]);

            $this->syncBookingStatusFromTechnicianResponse(
                $booking,
                $data['status'],
                $data['note'] ?? $technicianResponse->note
            );
        }

        $technicianResponse->refresh();
        $technicianResponse->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'technician',
        ]);

        try {
            NodeEventPublisher::publish('technician_response.updated', [
                'technician_response_id' => $technicianResponse->id,
                'damage_report_id'       => $technicianResponse->damageReport?->id,
                'booking_id'             => $booking->id,
                'technician_id'          => $technicianResponse->technician_id,
                'technician_name'        => $technician->name ?? $technician->username ?? null,
                'vehicle_id'             => $technicianResponse->damageReport?->vehicle_id,
                'equipment_name'         => $technicianResponse->damageReport?->vehicle?->equipment_name,
                'plate_number'           => $technicianResponse->damageReport?->vehicle?->plate_number,
                'driver_id'              => $technicianResponse->damageReport?->driver_id,
                'driver_name'            => $technicianResponse->damageReport?->driver?->name
                    ?? $technicianResponse->damageReport?->driver?->username,
                'status'                 => $technicianResponse->status,
                'status_label'           => $this->statusLabelForDriver($technicianResponse->status),
                'booking_status'         => $booking->status,
                'note'                   => $technicianResponse->note,
                'mttr'                   => $technicianResponse->mttr ?? null,
                'mtbf'                   => $technicianResponse->mtbf ?? null,
                'ma'                     => $technicianResponse->ma ?? null,
                'updated_at'             => optional($technicianResponse->updated_at)
                    ->timezone('Asia/Jakarta')
                    ->format('Y-m-d H:i:s'),
            ], ['admin', 'driver']);
        } catch (\Throwable $e) {
            Log::warning('Node event technician_response.updated gagal', [
                'message' => $e->getMessage(),
            ]);
        }

        if ($technicianResponse->status === 'butuh_followup_admin') {
            try {
                NodeEventPublisher::publish('damage_report.followup_created', [
                    'damage_report_id'        => $technicianResponse->damageReport?->id,
                    'booking_id'              => $booking->id,
                    'status'                  => $technicianResponse->status,
                    'technician_response_id'  => $technicianResponse->id,
                    'technician_id'           => $technicianResponse->technician_id,
                    'updated_at'              => optional($technicianResponse->updated_at)
                        ->timezone('Asia/Jakarta')
                        ->format('Y-m-d H:i:s'),
                ], ['admin']);
            } catch (\Throwable $e) {
                Log::warning('Node event damage_report.followup_created gagal', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message'  => 'Respons teknisi berhasil diupdate',
            'response' => $technicianResponse,
            'booking_status' => $booking->status,
        ]);
    }

    /**
     * Riwayat response teknisi login.
     */
    public function myResponses(Request $request)
    {
        $technician = $request->user();

        $responses = TechnicianResponse::with([
                'damageReport.vehicle',
                'damageReport.driver',
                'technician',
            ])
            ->where('technician_id', $technician->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($responses);
    }

    /**
     * Ambil booking aktif milik teknisi dari damage report.
     */
    private function getAssignedBooking(DamageReport $damageReport, int $technicianId): ?ServiceBooking
    {
        return ServiceBooking::with([
                'damageReport.vehicle',
                'damageReport.driver',
                'vehicle',
                'driver',
                'technician',
            ])
            ->where('damage_report_id', $damageReport->id)
            ->where('technician_id', $technicianId)
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
            ->latest()
            ->first();
    }

    /**
     * Ubah payload ServiceBooking menjadi DamageReport,
     * agar UI lama yang membaca damage report tetap aman.
     */
    private function bookingToDamageReportPayload(ServiceBooking $booking): ?DamageReport
    {
        $report = $booking->damageReport;

        if (!$report) {
            return null;
        }

        $report->setAttribute('latest_service_booking', $booking);
        $report->setAttribute('service_booking', $booking);
        $report->setAttribute('booking', $booking);
        $report->setAttribute('booking_status', $booking->status);
        $report->setAttribute('computed_status', $this->computedStatusFromBooking($booking, $report));

        return $report;
    }

    /**
     * Status gabungan booking + technician response.
     */
    private function computedStatusFromBooking(ServiceBooking $booking, DamageReport $report): string
    {
        $bookingStatus = strtolower((string) $booking->status);

        if (in_array($bookingStatus, ['approved', 'scheduled'], true)) {
            return 'menunggu';
        }

        if ($bookingStatus === 'rescheduled') {
            return 'menunggu';
        }

        if ($bookingStatus === 'in_progress') {
            return 'proses';
        }

        if (in_array($bookingStatus, ['completed', 'finished', 'selesai'], true)) {
            return 'selesai';
        }

        $latestStatus = $report->latestTechnicianResponse?->status;

        return $latestStatus ?? 'menunggu';
    }

    /**
     * Sinkronkan status booking saat teknisi update pekerjaan.
     */
    private function syncBookingStatusFromTechnicianResponse(
        ServiceBooking $booking,
        string $technicianStatus,
        ?string $note = null
    ): void {
        $update = [
            'note_technician' => $note,
        ];

        if ($technicianStatus === 'proses') {
            $update['status'] = 'in_progress';

            if (!$booking->started_at) {
                $update['started_at'] = now();
            }
        }

        if ($technicianStatus === 'butuh_followup_admin') {
            $update['status'] = 'in_progress';

            if (!$booking->started_at) {
                $update['started_at'] = now();
            }
        }

        if ($technicianStatus === 'fatal') {
            $update['status'] = 'in_progress';

            if (!$booking->started_at) {
                $update['started_at'] = now();
            }
        }

        if ($technicianStatus === 'selesai') {
            $update['status'] = 'completed';
            $update['completed_at'] = now();

            if (!$booking->started_at) {
                $update['started_at'] = now();
            }
        }

        $booking->update($update);
        $booking->refresh();
    }

    /**
     * Normalisasi status dari Flutter / frontend ke status backend.
     */
    private function normalizeStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $status = strtolower(trim($status));
        $status = str_replace([' ', '-'], '_', $status);

        return match ($status) {
            'reported',
            'waiting',
            'menunggu' => 'menunggu',

            'ongoing',
            'in_progress',
            'diproses',
            'proses' => 'proses',

            'on_hold',
            'waiting_parts',
            'menunggu_sparepart',
            'butuh_followup',
            'butuh_followup_admin' => 'butuh_followup_admin',

            'finished',
            'completed',
            'complete',
            'selesai' => 'selesai',

            'fatal' => 'fatal',

            default => $status,
        };
    }

    /**
     * Label status untuk tampilan driver/operator.
     */
    private function statusLabelForDriver(?string $status): string
    {
        return match ($status) {
            'menunggu' => 'Reported',
            'proses' => 'In Progress',
            'butuh_followup_admin' => 'Waiting Parts',
            'fatal' => 'Fatal',
            'selesai' => 'Completed',
            default => $status ?? 'Reported',
        };
    }
}