<?php

namespace App\Http\Controllers\Api\Technician;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use App\Models\ServiceBooking;
use App\Models\TechnicianResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * - status sudah approved/scheduled/rescheduled/in_progress/completed
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
            } else {
                $q->where('status', $status);
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

        $this->appendBookingMaintenanceAttributes($damageReport, $booking);

        return response()->json($damageReport);
    }

    /**
     * Teknisi memberi respons / update status laporan kerusakan.
     *
     * Controller ini adalah jalur legacy.
     * Untuk flow baru, complete job utama tetap melalui ServiceJobController.
     * Namun method ini tetap disesuaikan supaya jika dipakai page lama,
     * data maintenance tetap bisa dihitung dan disimpan.
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

        $request->validate($this->technicianResponseValidationRules(requiredStatus: true));

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

        $maintenanceData = $this->buildMaintenanceDataFromRequest($request);

        $response = null;

        DB::transaction(function () use (
            $request,
            $damageReport,
            $technician,
            $booking,
            $status,
            $maintenanceData,
            &$response
        ) {
            $data = [
                'damage_id' => $damageReport->id,
                'technician_id' => $technician->id,
                'status' => $status,
                'note' => $request->note,
            ];

            $this->applyMaintenanceDataToTechnicianResponsePayload($data, $maintenanceData);

            $response = TechnicianResponse::create($data);

            $reportPayload = [];

            if ($this->hasColumnOnTable('damage_reports', 'status')) {
                $reportPayload['status'] = $status;
            }

            if ($status === 'selesai') {
                if ($this->hasColumnOnTable('damage_reports', 'completed_at')) {
                    $reportPayload['completed_at'] = now();
                }

                if ($this->hasColumnOnTable('damage_reports', 'finished_at')) {
                    $reportPayload['finished_at'] = now();
                }
            }

            if (!empty($reportPayload)) {
                $damageReport->forceFill($reportPayload)->save();
            }

            $this->syncBookingStatusFromTechnicianResponse(
                $booking,
                $status,
                $request->note,
                $maintenanceData
            );
        });

        $response->load('technician');

        $damageReport->refresh()->load([
            'driver',
            'vehicle',
            'latestTechnicianResponse.technician',
            'technicianResponses.technician',
        ]);

        $booking->refresh()->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        Log::info('TECHNICIAN RESPOND BERHASIL DISIMPAN', [
            'technician_response_id' => $response->id,
            'damage_id' => $response->damage_id,
            'technician_id' => $response->technician_id,
            'status' => $response->status,
            'booking_id' => $booking->id,
            'booking_status' => $booking->status,
            'mttr' => $booking->mttr ?? null,
            'mtbf' => $booking->mtbf ?? null,
            'ma' => $booking->ma ?? null,
        ]);

        $this->notifyAfterTechnicianResponse($damageReport, $booking, $response, $status);

        return response()->json([
            'message' => 'Respons teknisi berhasil ditambahkan',
            'response' => $response,
            'damage_report_status' => $status,
            'booking_status' => $booking->status,
            'booking' => $booking,
            'maintenance_data' => $maintenanceData,
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

        $request->validate($this->technicianResponseValidationRules(requiredStatus: false));

        $data = [];
        $status = null;

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

        $maintenanceData = $this->buildMaintenanceDataFromRequest($request);
        $this->applyMaintenanceDataToTechnicianResponsePayload($data, $maintenanceData);

        DB::transaction(function () use (
            $request,
            $technicianResponse,
            $damageReport,
            $booking,
            $data,
            $status,
            $maintenanceData
        ) {
            if (!empty($data)) {
                $technicianResponse->forceFill($data)->save();
            }

            $effectiveStatus = $status ?? $technicianResponse->status;
            $effectiveNote = $data['note'] ?? $technicianResponse->note;

            if ($status && $technicianResponse->damageReport) {
                $reportPayload = [];

                if ($this->hasColumnOnTable('damage_reports', 'status')) {
                    $reportPayload['status'] = $status;
                }

                if ($status === 'selesai') {
                    if ($this->hasColumnOnTable('damage_reports', 'completed_at')) {
                        $reportPayload['completed_at'] = now();
                    }

                    if ($this->hasColumnOnTable('damage_reports', 'finished_at')) {
                        $reportPayload['finished_at'] = now();
                    }
                }

                if (!empty($reportPayload)) {
                    $damageReport->forceFill($reportPayload)->save();
                }
            }

            if ($effectiveStatus) {
                $this->syncBookingStatusFromTechnicianResponse(
                    $booking,
                    $effectiveStatus,
                    $effectiveNote,
                    $maintenanceData
                );
            }
        });

        $technicianResponse->refresh();
        $technicianResponse->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'technician',
        ]);

        $booking->refresh()->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        $this->publishTechnicianResponseUpdated($technicianResponse, $booking, $technician);

        return response()->json([
            'message' => 'Respons teknisi berhasil diupdate',
            'response' => $technicianResponse,
            'booking_status' => $booking->status,
            'booking' => $booking,
            'maintenance_data' => $maintenanceData,
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

        $this->appendBookingMaintenanceAttributes($report, $booking);

        return $report;
    }

    /**
     * Tambahkan field maintenance dari booking ke payload damage report.
     */
    private function appendBookingMaintenanceAttributes(DamageReport $report, ServiceBooking $booking): void
    {
        $report->setAttribute('booking_id', $booking->id);
        $report->setAttribute('service_booking_id', $booking->id);

        $report->setAttribute('scheduled_at', $booking->scheduled_at ?? null);
        $report->setAttribute('estimated_finish_at', $booking->estimated_finish_at ?? null);
        $report->setAttribute('started_at', $booking->started_at ?? null);
        $report->setAttribute('completed_at', $booking->completed_at ?? null);

        $report->setAttribute('note_driver', $booking->note_driver ?? null);
        $report->setAttribute('note_admin', $booking->note_admin ?? null);
        $report->setAttribute('note_technician', $booking->note_technician ?? null);

        foreach ([
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
        ] as $column) {
            if (isset($booking->{$column})) {
                $report->setAttribute($column, $booking->{$column});
            }
        }
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
        ?string $note = null,
        array $maintenanceData = []
    ): void {
        $update = [];

        if ($this->hasColumnOnTable($booking->getTable(), 'note_technician')) {
            $update['note_technician'] = $note;
        }

        if ($technicianStatus === 'proses') {
            $update['status'] = 'in_progress';

            if ($this->hasColumnOnTable($booking->getTable(), 'started_at') && !$booking->started_at) {
                $update['started_at'] = now();
            }
        }

        if ($technicianStatus === 'butuh_followup_admin') {
            $update['status'] = 'in_progress';

            if ($this->hasColumnOnTable($booking->getTable(), 'started_at') && !$booking->started_at) {
                $update['started_at'] = now();
            }
        }

        if ($technicianStatus === 'fatal') {
            $update['status'] = 'in_progress';

            if ($this->hasColumnOnTable($booking->getTable(), 'started_at') && !$booking->started_at) {
                $update['started_at'] = now();
            }
        }

        if ($technicianStatus === 'selesai') {
            $update['status'] = 'completed';

            if ($this->hasColumnOnTable($booking->getTable(), 'completed_at')) {
                $update['completed_at'] = now();
            }

            if ($this->hasColumnOnTable($booking->getTable(), 'started_at') && !$booking->started_at) {
                $update['started_at'] = now();
            }
        }

        $this->applyMaintenanceDataToBookingPayload($update, $booking, $maintenanceData);

        if (!empty($update)) {
            $booking->forceFill($update)->save();
            $booking->refresh();
        }

        $this->syncVehicleFromMaintenanceData($booking, $maintenanceData);
    }

    /**
     * Rules validasi response teknisi.
     */
    private function technicianResponseValidationRules(bool $requiredStatus = true): array
    {
        return [
            'status' => $requiredStatus ? 'required|string' : 'sometimes|string',
            'note' => 'nullable|string',

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

            'mttr' => 'nullable|numeric|min:0',
            'mtbf' => 'nullable|numeric|min:0',
            'ma' => 'nullable|numeric|min:0|max:100',
        ];
    }

    /**
     * Bangun data maintenance dari request.
     */
    private function buildMaintenanceDataFromRequest(Request $request): array
    {
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

        $legacyMttr = $this->numberFromRequest($request, ['mttr']);
        $legacyMtbf = $this->numberFromRequest($request, ['mtbf']);
        $legacyMa = $this->numberFromRequest($request, ['ma']);

        $safeFailureCount = ($failureCount !== null && $failureCount > 0)
            ? $failureCount
            : null;

        $mttr = null;
        if ($totalRepairTime !== null && $safeFailureCount !== null) {
            $mttr = round($totalRepairTime / $safeFailureCount, 2);
        } elseif ($legacyMttr !== null) {
            $mttr = round($legacyMttr, 2);
        }

        $mtbf = null;
        if ($totalOperationalTime !== null && $safeFailureCount !== null) {
            $mtbf = round($totalOperationalTime / $safeFailureCount, 2);
        } elseif ($legacyMtbf !== null) {
            $mtbf = round($legacyMtbf, 2);
        }

        $ma = null;
        if (
            $actualOperatingHours !== null &&
            $breakdownHours !== null &&
            ($actualOperatingHours + $breakdownHours) > 0
        ) {
            $ma = round(
                ($actualOperatingHours / ($actualOperatingHours + $breakdownHours)) * 100,
                1
            );
        } elseif ($legacyMa !== null) {
            $ma = round($legacyMa, 1);
        }

        return array_filter([
            'final_hour_meter' => $finalHourMeter,
            'current_hour_meter' => $finalHourMeter,
            'latest_hour_meter' => $finalHourMeter,
            'total_repair_time' => $totalRepairTime,
            'repair_time' => $totalRepairTime,
            'repair_time_hours' => $totalRepairTime,
            'total_operational_time' => $totalOperationalTime,
            'operational_time' => $totalOperationalTime,
            'operational_time_hours' => $totalOperationalTime,
            'failure_count' => $failureCount,
            'number_of_failures' => $failureCount,
            'failures' => $failureCount,
            'actual_operating_hours' => $actualOperatingHours,
            'actual_operation_hours' => $actualOperatingHours,
            'breakdown_hours' => $breakdownHours,
            'breakdown_time' => $breakdownHours,
            'mttr' => $mttr,
            'mtbf' => $mtbf,
            'ma' => $ma,
        ], fn ($value) => $value !== null);
    }

    /**
     * Apply data maintenance ke payload TechnicianResponse jika kolomnya tersedia.
     */
    private function applyMaintenanceDataToTechnicianResponsePayload(array &$payload, array $maintenanceData): void
    {
        foreach ($maintenanceData as $key => $value) {
            if ($this->hasColumnOnTable('technician_responses', $key)) {
                $payload[$key] = $value;
            }
        }
    }

    /**
     * Apply data maintenance ke payload ServiceBooking jika kolomnya tersedia.
     */
    private function applyMaintenanceDataToBookingPayload(
        array &$payload,
        ServiceBooking $booking,
        array $maintenanceData
    ): void {
        foreach ($maintenanceData as $key => $value) {
            if ($this->hasColumnOnTable($booking->getTable(), $key)) {
                $payload[$key] = $value;
            }
        }
    }

    /**
     * Sinkronkan vehicle dari data maintenance.
     * Initial tidak diubah.
     */
    private function syncVehicleFromMaintenanceData(ServiceBooking $booking, array $maintenanceData): void
    {
        $vehicle = $this->getVehicleFromBooking($booking);

        if (!$vehicle) {
            return;
        }

        $payload = [];

        $finalHourMeter = $maintenanceData['final_hour_meter']
            ?? $maintenanceData['current_hour_meter']
            ?? $maintenanceData['latest_hour_meter']
            ?? null;

        $ma = $maintenanceData['ma'] ?? null;

        if ($finalHourMeter !== null) {
            foreach (['current_hour_meter', 'latest_hour_meter', 'final_hour_meter'] as $column) {
                if ($this->hasColumnOnTable($vehicle->getTable(), $column)) {
                    $payload[$column] = $finalHourMeter;
                }
            }
        }

        if ($ma !== null) {
            foreach (['current_ma', 'ma', 'mechanical_availability'] as $column) {
                if ($this->hasColumnOnTable($vehicle->getTable(), $column)) {
                    $payload[$column] = $ma;
                }
            }
        }

        foreach (['last_repair_at', 'last_maintenance_at'] as $column) {
            if ($this->hasColumnOnTable($vehicle->getTable(), $column)) {
                $payload[$column] = now();
            }
        }

        if ($this->hasColumnOnTable($vehicle->getTable(), 'status')) {
            $payload['status'] = 'active';
        }

        if (!empty($payload)) {
            $vehicle->forceFill($payload)->save();
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
     * Notifikasi setelah respond dibuat.
     */
    private function notifyAfterTechnicianResponse(
        DamageReport $damageReport,
        ServiceBooking $booking,
        TechnicianResponse $response,
        string $status
    ): void {
        try {
            $fcm = app(FcmService::class);

            if ($damageReport->driver) {
                $fcm->sendToUser(
                    $damageReport->driver,
                    'Update Laporan Kendaraan',
                    'Status laporan kamu: ' . $this->statusLabelForDriver($status),
                    [
                        'type' => 'damage_report',
                        'role' => 'driver',
                        'report_id' => (string) $damageReport->id,
                        'booking_id' => (string) $booking->id,
                        'status' => (string) $status,
                        'mttr' => (string) ($booking->mttr ?? ''),
                        'mtbf' => (string) ($booking->mtbf ?? ''),
                        'ma' => (string) ($booking->ma ?? ''),
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
                        'type' => 'damage_report',
                        'role' => 'admin',
                        'report_id' => (string) $damageReport->id,
                        'booking_id' => (string) $booking->id,
                        'status' => (string) $status,
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
                'damage_report_id' => $damageReport->id,
                'booking_id' => $booking->id,
                'technician_id' => $response->technician_id,
                'technician_name' => $response->technician?->name
                    ?? $response->technician?->username
                    ?? null,
                'vehicle_id' => $damageReport->vehicle_id,
                'equipment_name' => $damageReport->vehicle?->equipment_name,
                'plate_number' => $damageReport->vehicle?->plate_number,
                'driver_id' => $damageReport->driver_id,
                'driver_name' => $damageReport->driver?->name ?? $damageReport->driver?->username,
                'status' => $response->status,
                'status_label' => $this->statusLabelForDriver($response->status),
                'booking_status' => $booking->status,
                'note' => $response->note,
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
                'created_at' => optional($response->created_at)
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
                    'damage_report_id' => $damageReport->id,
                    'booking_id' => $booking->id,
                    'status' => $response->status,
                    'technician_response_id' => $response->id,
                    'technician_id' => $response->technician_id,
                    'updated_at' => now('Asia/Jakarta')->format('Y-m-d H:i:s'),
                ], ['admin']);
            } catch (\Throwable $e) {
                Log::warning('Node event damage_report.followup_created gagal', [
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Publish update response.
     */
    private function publishTechnicianResponseUpdated(
        TechnicianResponse $technicianResponse,
        ServiceBooking $booking,
        $technician
    ): void {
        try {
            NodeEventPublisher::publish('technician_response.updated', [
                'technician_response_id' => $technicianResponse->id,
                'damage_report_id' => $technicianResponse->damageReport?->id,
                'booking_id' => $booking->id,
                'technician_id' => $technicianResponse->technician_id,
                'technician_name' => $technician->name ?? $technician->username ?? null,
                'vehicle_id' => $technicianResponse->damageReport?->vehicle_id,
                'equipment_name' => $technicianResponse->damageReport?->vehicle?->equipment_name,
                'plate_number' => $technicianResponse->damageReport?->vehicle?->plate_number,
                'driver_id' => $technicianResponse->damageReport?->driver_id,
                'driver_name' => $technicianResponse->damageReport?->driver?->name
                    ?? $technicianResponse->damageReport?->driver?->username,
                'status' => $technicianResponse->status,
                'status_label' => $this->statusLabelForDriver($technicianResponse->status),
                'booking_status' => $booking->status,
                'note' => $technicianResponse->note,
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
                'updated_at' => optional($technicianResponse->updated_at)
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
                    'damage_report_id' => $technicianResponse->damageReport?->id,
                    'booking_id' => $booking->id,
                    'status' => $technicianResponse->status,
                    'technician_response_id' => $technicianResponse->id,
                    'technician_id' => $technicianResponse->technician_id,
                    'updated_at' => optional($technicianResponse->updated_at)
                        ->timezone('Asia/Jakarta')
                        ->format('Y-m-d H:i:s'),
                ], ['admin']);
            } catch (\Throwable $e) {
                Log::warning('Node event damage_report.followup_created gagal', [
                    'message' => $e->getMessage(),
                ]);
            }
        }
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

    /**
     * Helper aman untuk cek kolom.
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
