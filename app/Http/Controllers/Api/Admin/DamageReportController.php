<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use App\Models\Repair;
use App\Models\ServiceBooking;
use App\Models\TechnicianResponse;
use App\Models\TechnicianPartUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use App\Services\NodeEventPublisher;

class DamageReportController extends Controller
{
    /**
     * Admin melihat semua laporan kerusakan.
     */
    public function index(Request $request)
    {
        try {
            $q = DamageReport::query()
                ->with([
                    'vehicle',
                    'driver',
                    'technicianResponses.technician',
                    'latestTechnicianResponse.technician',
                    'booking',
                ])
                ->orderBy('created_at', 'desc');

            $statusFilter = null;

            if ($request->filled('status')) {
                $statusFilter = $this->normalizeStatus($request->query('status'));
            }

            if ($request->filled('search')) {
                $search = trim($request->query('search'));

                $q->where(function ($query) use ($search) {
                    $query->where('damage_type', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('vehicle', function ($vehicle) use ($search) {
                            $vehicle->where('equipment_name', 'like', "%{$search}%")
                                ->orWhere('plate_number', 'like', "%{$search}%");
                        })
                        ->orWhereHas('driver', function ($driver) use ($search) {
                            $driver->where('username', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        });
                });
            }

            if ($request->filled('limit')) {
                $limit = (int) $request->query('limit');

                if ($limit > 0) {
                    $q->limit($limit);
                }
            }

            $reports = $q->get();

            $reports = $this->attachDamageReportStatusInfo($reports);

            if ($statusFilter) {
                $reports = $reports
                    ->filter(function ($report) use ($statusFilter) {
                        return $this->normalizeStatus(
                            $report->getAttribute('computed_status')
                                ?? $report->getAttribute('status')
                        ) === $statusFilter;
                    })
                    ->values();
            }

            $reports = $this->attachRepairHistoryInfo($reports);
            $reports = $this->attachPartUsageInfo($reports);

            return response()->json($reports);
        } catch (\Throwable $e) {
            Log::error('DamageReportController@index error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Gagal mengambil data damage reports.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin melihat detail laporan kerusakan.
     */
    public function show(DamageReport $damageReport)
    {
        try {
            $damageReport->load([
                'vehicle',
                'driver',
                'technicianResponses.technician',
                'latestTechnicianResponse.technician',
                'booking',
            ]);

            $repair = $this->getRepairHistory($damageReport->id);

            $damageReport->setAttribute('repair_history_saved', $repair ? true : false);
            $damageReport->setAttribute('repair_history', $repair);

            $this->attachSingleDamageReportStatusInfo($damageReport);
            $this->attachSinglePartUsageInfo($damageReport);

            return response()->json($damageReport);
        } catch (\Throwable $e) {
            Log::error('DamageReportController@show error: ' . $e->getMessage(), [
                'damage_report_id' => $damageReport->id ?? null,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Gagal mengambil detail damage report.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin melihat laporan yang butuh follow-up.
     */
    public function followUps()
    {
        try {
            $reports = DamageReport::with([
                    'vehicle',
                    'driver',
                    'latestTechnicianResponse.technician',
                    'technicianResponses.technician',
                ])
                ->whereHas('latestTechnicianResponse', function ($q) {
                    $q->where('status', 'butuh_followup_admin');
                })
                ->orderByDesc(
                    TechnicianResponse::select('created_at')
                        ->whereColumn('technician_responses.damage_id', 'damage_reports.id')
                        ->latest()
                        ->take(1)
                )
                ->get();

            $reports = $this->attachDamageReportStatusInfo($reports);
            $reports = $this->attachRepairHistoryInfo($reports);
            $reports = $this->attachPartUsageInfo($reports);

            return response()->json($reports);
        } catch (\Throwable $e) {
            Log::error('DamageReportController@followUps error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Gagal mengambil data follow-up.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin melihat semua laporan teknisi yang sudah selesai.
     */
    public function finishedRepairs()
    {
        try {
            $reports = DamageReport::with([
                    'vehicle',
                    'driver',
                    'technicianResponses.technician',
                    'latestTechnicianResponse.technician',
                    'booking',
                    'review',
                ])
                ->where(function ($query) {
                    $query->whereHas('latestTechnicianResponse', function ($q) {
                        $q->where('status', 'selesai');
                    })
                    ->orWhereHas('booking', function ($booking) {
                        $booking->whereIn('status', [
                            'completed',
                            'finished',
                            'selesai',
                        ]);
                    });
                })
                ->orderByDesc(
                    TechnicianResponse::select('created_at')
                        ->whereColumn('technician_responses.damage_id', 'damage_reports.id')
                        ->latest()
                        ->take(1)
                )
                ->orderByDesc('updated_at')
                ->get();

            $reports = $this->attachRepairHistoryInfo($reports);
            $reports = $this->attachPartUsageInfo($reports);

            return response()->json($reports);
        } catch (\Throwable $e) {
            Log::error('DamageReportController@finishedRepairs error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Gagal mengambil finished repairs.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin menyimpan finished repair history dari hasil teknisi.
     */
    public function storeFinishedRepairHistory(Request $request, DamageReport $damageReport)
    {
        $request->validate([
            'admin_note' => 'nullable|string',
            'action'     => 'nullable|string',
            'cost'       => 'nullable|numeric|min:0',
        ]);

        $damageReport->load([
            'vehicle',
            'driver',
            'latestTechnicianResponse.technician',
            'technicianResponses.technician',
            'booking',
        ]);

        $latest = $damageReport->latestTechnicianResponse;
        $completedBooking = $this->getCompletedServiceBooking($damageReport);

        $isFinishedFromOldFlow =
            $latest &&
            $latest->status === 'selesai';

        $isFinishedFromServiceBooking =
            $completedBooking &&
            in_array($completedBooking->status, [
                'completed',
                'finished',
                'selesai',
            ], true);

        if (!$isFinishedFromOldFlow && !$isFinishedFromServiceBooking) {
            return response()->json([
                'message' => 'Laporan ini belum selesai dari teknisi.',
                'debug' => [
                    'latest_status' => $latest?->status,
                    'damage_report_status' => $damageReport->status ?? null,
                    'service_booking_status' => $completedBooking?->status,
                    'service_booking_id' => $completedBooking?->id,
                ],
            ], 422);
        }

        try {
            $repair = DB::transaction(function () use (
                $request,
                $damageReport,
                &$latest,
                $completedBooking,
                $isFinishedFromOldFlow
            ) {
                if (!$isFinishedFromOldFlow && $completedBooking) {
                    $latest = $damageReport->technicianResponses()->create([
                        'damage_id'     => $damageReport->id,
                        'technician_id' => $completedBooking->technician_id,
                        'status'        => 'selesai',
                        'note'          => $completedBooking->note_technician
                            ?? $completedBooking->note_admin
                            ?? 'Repair selesai oleh teknisi',
                        'mttr'          => $completedBooking->mttr ?? null,
                        'mtbf'          => $completedBooking->mtbf ?? null,
                        'ma'            => $completedBooking->ma ?? null,
                    ]);
                }

                $damageReport->update([
                    'status' => 'selesai',
                ]);

                $technicianId =
                    $latest?->technician_id ??
                    $completedBooking?->technician_id;

                $action =
                    $request->action
                    ?? $latest?->note
                    ?? $completedBooking?->note_technician
                    ?? $completedBooking?->note_admin
                    ?? $request->admin_note
                    ?? 'Repair selesai oleh teknisi';

                $repair = Repair::updateOrCreate(
                    [
                        'damage_report_id' => $damageReport->id,
                    ],
                    [
                        'vehicle_plate' => optional($damageReport->vehicle)->plate_number ?? 'UNKNOWN',
                        'technician_id' => $technicianId,
                        'action'        => $action,
                        'cost'          => $request->cost ?? 0,
                        'repair_date'   => now()->toDateString(),
                        'finalized'     => true,
                        'finalized_at'  => now(),
                    ]
                );

                return $repair;
            });
        } catch (\Throwable $e) {
            Log::error('storeFinishedRepairHistory transaction error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Gagal menyimpan finished repair history.',
                'error' => $e->getMessage(),
            ], 500);
        }

        try {
            $repair = $this->getRepairHistory($damageReport->id) ?? $repair;

            $damageReport->refresh();
            $damageReport->load([
                'vehicle',
                'driver',
                'technicianResponses.technician',
                'latestTechnicianResponse.technician',
                'booking',
            ]);

            $damageReport->setAttribute('repair_history_saved', true);
            $damageReport->setAttribute('repair_history', $repair);

            $this->attachSingleDamageReportStatusInfo($damageReport);
            $this->attachSinglePartUsageInfo($damageReport);

            $completedBooking = $this->getCompletedServiceBooking($damageReport);

            try {
                NodeEventPublisher::publish('repair.finished_saved', [
                    'repair_id'        => $repair->id,
                    'damage_report_id' => $damageReport->id,
                    'vehicle_id'       => $damageReport->vehicle_id,
                    'vehicle_plate'    => $repair->vehicle_plate,
                    'equipment_name'   => $damageReport->vehicle?->equipment_name,
                    'driver_id'        => $damageReport->driver_id,
                    'technician_id'    => $repair->technician_id,
                    'status'           => 'selesai',
                    'finalized'        => true,
                    'repair_date'      => optional($repair->repair_date)?->toDateString(),
                    'finalized_at'     => optional($repair->finalized_at)?->toISOString(),
                    'mttr'             => $damageReport->latestTechnicianResponse?->mttr
                        ?? $completedBooking?->mttr,
                    'mtbf'             => $damageReport->latestTechnicianResponse?->mtbf
                        ?? $completedBooking?->mtbf,
                    'ma'               => $damageReport->latestTechnicianResponse?->ma
                        ?? $completedBooking?->ma,
                    'created_at'       => optional($repair->created_at)?->toISOString(),
                    'updated_at'       => optional($repair->updated_at)?->toISOString(),
                ], ['admin']);
            } catch (\Throwable $e) {
                Log::error('NodeEventPublisher repair.finished_saved error: ' . $e->getMessage());
            }

            return response()->json([
                'message' => 'Finished repair history berhasil disimpan ke admin.',
                'damage_report' => $damageReport,
                'latest_technician_response' => $damageReport->latestTechnicianResponse,
                'completed_service_booking' => $completedBooking,
                'repair' => $repair,
                'part_usages' => $damageReport->getAttribute('part_usages'),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('storeFinishedRepairHistory response error: ' . $e->getMessage());

            return response()->json([
                'message' => 'Repair tersimpan, tetapi gagal memuat ulang detail.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Admin approve follow-up.
     */
    public function markAsCompleted(Request $request, DamageReport $damageReport)
    {
        $request->validate([
            'admin_note' => 'nullable|string',
            'note'       => 'nullable|string',
        ]);

        $adminNote = $request->admin_note
            ?? $request->note
            ?? 'Approved by admin';

        $damageReport->load([
            'vehicle',
            'latestTechnicianResponse',
        ]);

        $latest = $damageReport->latestTechnicianResponse;

        if (!$latest || $latest->status !== 'butuh_followup_admin') {
            return response()->json([
                'message' => 'Laporan ini tidak dalam status butuh follow-up admin.',
                'debug'   => [
                    'latest_status' => $latest?->status,
                    'dr_status'     => $damageReport->status ?? null,
                ],
            ], 422);
        }

        try {
            DB::transaction(function () use ($damageReport) {
                $damageReport->update([
                    'status' => 'approved_followup_admin',
                ]);

                Repair::firstOrCreate(
                    ['damage_report_id' => $damageReport->id],
                    [
                        'vehicle_plate' => optional($damageReport->vehicle)->plate_number ?? 'UNKNOWN',
                        'finalized'     => false,
                        'repair_date'   => now()->toDateString(),
                        'cost'          => 0,
                    ]
                );
            });

            /**
             * Log technician response dibuat terpisah agar proses approve tidak gagal
             * hanya karena kolom technician_id tidak nullable atau status log tidak cocok.
             */
            $this->createAdminTechnicianResponseLog(
                $damageReport,
                'approved_followup_admin',
                $adminNote
            );
        } catch (\Throwable $e) {
            Log::error('markAsCompleted error: ' . $e->getMessage(), [
                'damage_report_id' => $damageReport->id ?? null,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }

        $repair = Repair::where('damage_report_id', $damageReport->id)->first();

        try {
            NodeEventPublisher::publish('damage_report.followup_approved', [
                'damage_report_id' => $damageReport->id,
                'status'           => 'approved_followup_admin',
                'admin_note'       => $adminNote,
                'updated_at'       => now(),
            ], ['admin']);

            if ($repair) {
                NodeEventPublisher::publish('repair.created', [
                    'repair_id'        => $repair->id,
                    'damage_report_id' => $damageReport->id,
                    'finalized'        => false,
                    'vehicle_plate'    => $repair->vehicle_plate,
                    'repair_date'      => optional($repair->repair_date)?->toDateString(),
                    'created_at'       => $repair->created_at,
                ], ['admin']);
            }
        } catch (\Throwable $e) {
            Log::error('NodeEventPublisher error: ' . $e->getMessage());
        }

        $damageReport->refresh();
        $damageReport->load([
            'vehicle',
            'driver',
            'technicianResponses.technician',
            'latestTechnicianResponse.technician',
        ]);

        $repair = Repair::where('damage_report_id', $damageReport->id)->first();

        $damageReport->setAttribute('repair_history_saved', $repair ? true : false);
        $damageReport->setAttribute('repair_history', $repair);

        $this->attachSingleDamageReportStatusInfo($damageReport);
        $this->attachSingleDamageReportStatusInfo($damageReport);
        $this->attachSinglePartUsageInfo($damageReport);

        return response()->json([
            'message'       => 'Follow-up disetujui & repair draft dibuat',
            'damage_report' => $damageReport,
            'repair'        => $repair,
            'part_usages'   => $damageReport->getAttribute('part_usages'),
        ]);
    }

    /**
     * Admin reject damage report.
     */
    public function reject(Request $request, DamageReport $damageReport)
    {
        $request->validate([
            'note'       => 'nullable|string',
            'reason'     => 'nullable|string',
            'admin_note' => 'nullable|string',
            'note_admin' => 'nullable|string',
        ]);

        $note = $request->note
            ?? $request->reason
            ?? $request->admin_note
            ?? $request->note_admin
            ?? 'Rejected by admin';

        try {
            DB::transaction(function () use ($damageReport, $note) {
                $damageReportTable = $damageReport->getTable();

                $updatePayload = [
                    'status' => 'rejected',
                ];

                if (Schema::hasColumn($damageReportTable, 'note_admin')) {
                    $updatePayload['note_admin'] = $note;
                }

                if (Schema::hasColumn($damageReportTable, 'admin_note')) {
                    $updatePayload['admin_note'] = $note;
                }

                if (Schema::hasColumn($damageReportTable, 'rejected_at')) {
                    $updatePayload['rejected_at'] = now();
                }

                if (Schema::hasColumn($damageReportTable, 'rejected_by')) {
                    $updatePayload['rejected_by'] = auth()->id();
                }

                $damageReport->update($updatePayload);
            });

            /**
             * Log technician response dibuat terpisah agar proses reject tidak gagal
             * hanya karena kolom technician_id tidak nullable atau enum status log tidak cocok.
             */
            $this->createAdminTechnicianResponseLog(
                $damageReport,
                'rejected',
                $note
            );
        } catch (\Throwable $e) {
            Log::error('reject damage report error: ' . $e->getMessage(), [
                'damage_report_id' => $damageReport->id ?? null,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Gagal reject damage report.',
                'error' => $e->getMessage(),
            ], 500);
        }

        $damageReport->refresh();
        $damageReport->load([
            'vehicle',
            'driver',
            'technicianResponses.technician',
            'latestTechnicianResponse.technician',
            'booking',
        ]);

        $this->attachSinglePartUsageInfo($damageReport);

        return response()->json([
            'message' => 'Damage report berhasil ditolak.',
            'data' => $damageReport,
        ]);
    }

    /**
     * Tambahkan status tampilan ke setiap damage report.
     *
     * PENTING:
     * - Jika booking baru approved/rescheduled, laporan tetap reported/dilaporkan.
     * - Jika teknisi start job, laporan baru menjadi proses/in_progress.
     * - Jika booking rejected/canceled/completed, laporan mengikuti status final tersebut.
     */
    private function attachDamageReportStatusInfo($reports)
    {
        return $reports->map(function ($report) {
            return $this->attachSingleDamageReportStatusInfo($report);
        });
    }

    /**
     * Tambahkan status tampilan ke satu damage report.
     */
    private function attachSingleDamageReportStatusInfo(DamageReport $damageReport)
    {
        try {
            if (!$damageReport->relationLoaded('booking')) {
                $damageReport->loadMissing('booking');
            }
        } catch (\Throwable $e) {
            // Relasi booking mungkin belum ada di model lama.
        }

        $booking = $damageReport->relationLoaded('booking')
            ? $damageReport->booking
            : null;

        $computedStatus = $this->resolveDamageReportDisplayStatus($damageReport, $booking);

        $damageReport->setAttribute('damage_report_status', $damageReport->getOriginal('status') ?? $damageReport->status);
        $damageReport->setAttribute('booking_status', $booking?->status);
        $damageReport->setAttribute('service_booking_status', $booking?->status);
        $damageReport->setAttribute('computed_status', $computedStatus);
        $damageReport->setAttribute('display_status', $computedStatus);

        /**
         * Frontend lama membaca field status langsung.
         * Karena itu status response disesuaikan agar tidak salah tampil.
         * Nilai database tidak berubah oleh setAttribute ini selama tidak di-save.
         */
        $damageReport->setAttribute('status', $computedStatus);

        return $damageReport;
    }

    /**
     * Tentukan status tampilan Damage Report.
     */
    private function resolveDamageReportDisplayStatus(DamageReport $damageReport, $booking = null): string
    {
        $bookingStatus = $this->normalizeServiceBookingStatus($booking?->status);

        /**
         * Status final dari booking harus langsung terlihat di laporan.
         */
        if ($bookingStatus === 'rejected') {
            return 'rejected';
        }

        if ($bookingStatus === 'canceled') {
            return 'canceled';
        }

        if ($bookingStatus === 'completed') {
            return 'selesai';
        }

        /**
         * Baru menjadi proses jika teknisi benar-benar start job.
         */
        if ($bookingStatus === 'in_progress') {
            return 'proses';
        }

        /**
         * Booking approved/rescheduled hanya berarti admin sudah menjadwalkan.
         * Teknisi belum mulai kerja, jadi laporan tetap dilaporkan.
         */
        if (in_array($bookingStatus, [
            'requested',
            'approved',
            'rescheduled',
            'scheduled',
        ], true)) {
            return 'reported';
        }

        $latestStatus = $this->normalizeStatus($damageReport->latestTechnicianResponse?->status);

        if (in_array($latestStatus, [
            'proses',
            'selesai',
            'butuh_followup_admin',
            'approved_followup_admin',
            'fatal',
            'rejected',
            'canceled',
        ], true)) {
            return $latestStatus;
        }

        $reportStatus = $this->normalizeStatus($damageReport->status);

        if (in_array($reportStatus, [
            'proses',
            'selesai',
            'butuh_followup_admin',
            'approved_followup_admin',
            'fatal',
            'rejected',
            'canceled',
        ], true)) {
            return $reportStatus;
        }

        return 'reported';
    }

    /**
     * Normalisasi status service booking tanpa mengubah arti untuk damage report.
     */
    private function normalizeServiceBookingStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $status = strtolower(trim($status));
        $status = str_replace([' ', '-'], '_', $status);

        return match ($status) {
            'requested',
            'pending',
            'waiting',
            'reported',
            'menunggu' => 'requested',

            'approved',
            'scheduled' => 'approved',

            'rescheduled' => 'rescheduled',

            'in_progress',
            'ongoing',
            'proses',
            'diproses',
            'started',
            'job_started',
            'repair_started',
            'technician_started',
            'working' => 'in_progress',

            'completed',
            'finished',
            'selesai',
            'done',
            'closed' => 'completed',

            'reject',
            'rejected',
            'ditolak' => 'rejected',

            'cancel',
            'canceled',
            'cancelled',
            'dibatalkan' => 'canceled',

            default => $status,
        };
    }

    /**
     * Buat log status admin ke technician_responses secara aman.
     *
     * Log ini tidak boleh membuat proses utama gagal.
     * Jika struktur tabel technician_responses berbeda, error hanya dicatat ke log.
     */
    private function createAdminTechnicianResponseLog(
        DamageReport $damageReport,
        string $status,
        ?string $note = null
    ): void {
        try {
            $responseTable = (new TechnicianResponse())->getTable();

            if (!Schema::hasTable($responseTable)) {
                return;
            }

            $payload = [
                'status' => $status,
                'note'   => $note ?: 'Updated by admin',
            ];

            if (Schema::hasColumn($responseTable, 'damage_id')) {
                $payload['damage_id'] = $damageReport->id;
            }

            if (Schema::hasColumn($responseTable, 'damage_report_id')) {
                $payload['damage_report_id'] = $damageReport->id;
            }

            if (Schema::hasColumn($responseTable, 'technician_id')) {
                /**
                 * Diisi auth()->id() agar tidak gagal jika technician_id tidak nullable.
                 * Jika database menolak karena constraint role/foreign key, catch di bawah
                 * akan menangkap error dan proses utama tetap berhasil.
                 */
                $payload['technician_id'] = auth()->id();
            }

            $damageReport->technicianResponses()->create($payload);
        } catch (\Throwable $e) {
            Log::warning('Gagal membuat admin technician response log.', [
                'damage_report_id' => $damageReport->id ?? null,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tambahkan informasi repair history ke setiap damage report.
     */
    private function attachRepairHistoryInfo($reports)
    {
        $ids = $reports->pluck('id')->filter()->values();

        if ($ids->isEmpty()) {
            return $reports;
        }

        try {
            if (
                !Schema::hasTable('repairs') ||
                !Schema::hasColumn('repairs', 'damage_report_id')
            ) {
                return $reports->map(function ($report) {
                    $report->setAttribute('repair_history_saved', false);
                    $report->setAttribute('repair_history', null);

                    return $report;
                });
            }

            $repairs = Repair::whereIn('damage_report_id', $ids)
                ->get()
                ->keyBy('damage_report_id');

            return $reports->map(function ($report) use ($repairs) {
                $repair = $repairs->get($report->id);

                $report->setAttribute('repair_history_saved', $repair ? true : false);
                $report->setAttribute('repair_history', $repair);

                return $report;
            });
        } catch (\Throwable $e) {
            Log::error('attachRepairHistoryInfo error: ' . $e->getMessage());

            return $reports->map(function ($report) {
                $report->setAttribute('repair_history_saved', false);
                $report->setAttribute('repair_history', null);

                return $report;
            });
        }
    }

    /**
     * Tambahkan informasi sparepart usage ke semua damage report.
     *
     * Data sparepart teknisi diambil dari TechnicianPartUsage,
     * karena controller teknisi menyimpan request sparepart ke model itu.
     */
    private function attachPartUsageInfo($reports)
    {
        $ids = $reports->pluck('id')->filter()->values();

        if ($ids->isEmpty()) {
            return $reports;
        }

        try {
            $foreignKey = $this->getTechnicianPartUsageForeignKey();

            if (!$foreignKey) {
                return $reports->map(function ($report) {
                    return $this->setEmptyPartUsageAttributes($report);
                });
            }

            $query = TechnicianPartUsage::query();

            $relations = $this->getTechnicianPartUsageRelations();

            if (!empty($relations)) {
                $query->with($relations);
            }

            $partUsages = $query
                ->whereIn($foreignKey, $ids)
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($usage) use ($foreignKey) {
                    return $this->serializeTechnicianPartUsage($usage, $foreignKey);
                })
                ->groupBy('damage_report_id');

            return $reports->map(function ($report) use ($partUsages) {
                $usages = $partUsages->get($report->id, collect())->values();

                $report->setAttribute('part_usages', $usages);
                $report->setAttribute('partUsages', $usages);
                $report->setAttribute('spare_parts', $usages);
                $report->setAttribute('spareParts', $usages);

                return $report;
            });
        } catch (\Throwable $e) {
            Log::error('attachPartUsageInfo error: ' . $e->getMessage());

            return $reports->map(function ($report) {
                return $this->setEmptyPartUsageAttributes($report);
            });
        }
    }

    /**
     * Tambahkan informasi sparepart usage ke satu damage report.
     */
    private function attachSinglePartUsageInfo(DamageReport $damageReport)
    {
        try {
            $foreignKey = $this->getTechnicianPartUsageForeignKey();

            if (!$foreignKey) {
                return $this->setEmptyPartUsageAttributes($damageReport);
            }

            $query = TechnicianPartUsage::query();

            $relations = $this->getTechnicianPartUsageRelations();

            if (!empty($relations)) {
                $query->with($relations);
            }

            $usages = $query
                ->where($foreignKey, $damageReport->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($usage) use ($foreignKey) {
                    return $this->serializeTechnicianPartUsage($usage, $foreignKey);
                })
                ->values();

            $damageReport->setAttribute('part_usages', $usages);
            $damageReport->setAttribute('partUsages', $usages);
            $damageReport->setAttribute('spare_parts', $usages);
            $damageReport->setAttribute('spareParts', $usages);

            return $damageReport;
        } catch (\Throwable $e) {
            Log::error('attachSinglePartUsageInfo error: ' . $e->getMessage());

            return $this->setEmptyPartUsageAttributes($damageReport);
        }
    }

    /**
     * Ambil nama tabel dari model TechnicianPartUsage.
     */
    private function getTechnicianPartUsageTableName(): string
    {
        return (new TechnicianPartUsage())->getTable();
    }

    /**
     * Ambil FK damage report dari tabel TechnicianPartUsage.
     */
    private function getTechnicianPartUsageForeignKey(): ?string
    {
        $table = $this->getTechnicianPartUsageTableName();

        if (!Schema::hasTable($table)) {
            return null;
        }

        if (Schema::hasColumn($table, 'damage_report_id')) {
            return 'damage_report_id';
        }

        if (Schema::hasColumn($table, 'damage_id')) {
            return 'damage_id';
        }

        return null;
    }

    /**
     * Ambil relasi TechnicianPartUsage hanya jika method relasinya ada.
     */
    private function getTechnicianPartUsageRelations(): array
    {
        $model = new TechnicianPartUsage();
        $relations = [];

        if (method_exists($model, 'part')) {
            $relations[] = 'part';
        }

        if (method_exists($model, 'technician')) {
            $relations[] = 'technician';
        }

        if (method_exists($model, 'damageReport')) {
            $relations[] = 'damageReport';
        }

        return $relations;
    }

    /**
     * Serialize TechnicianPartUsage menjadi array sederhana.
     */
    private function serializeTechnicianPartUsage(
        TechnicianPartUsage $usage,
        string $foreignKey
    ): array {
        $part = $usage->relationLoaded('part') ? $usage->part : null;
        $technician = $usage->relationLoaded('technician') ? $usage->technician : null;

        return [
            'id' => $usage->id,
            'damage_report_id' => $usage->{$foreignKey} ?? null,
            'damage_id' => $usage->{$foreignKey} ?? null,
            'part_id' => $usage->part_id ?? null,
            'technician_id' => $usage->technician_id ?? null,
            'qty' => $usage->qty ?? $usage->quantity ?? 0,
            'quantity' => $usage->qty ?? $usage->quantity ?? 0,
            'note' => $usage->note ?? null,
            'admin_note' => $usage->admin_note ?? null,
            'status' => $usage->status ?? 'requested',
            'created_at' => optional($usage->created_at)->toDateTimeString(),
            'updated_at' => optional($usage->updated_at)->toDateTimeString(),

            'part' => $part ? [
                'id' => $part->id,
                'sku' => $part->sku ?? $part->code ?? null,
                'code' => $part->code ?? $part->sku ?? null,
                'name' => $part->name ?? $part->part_name ?? null,
                'part_name' => $part->part_name ?? $part->name ?? null,
                'stock' => $part->stock ?? $part->qty ?? $part->quantity ?? null,
                'unit' => $part->unit ?? null,
            ] : null,

            'technician' => $technician ? [
                'id' => $technician->id,
                'name' => $technician->name
                    ?? $technician->username
                    ?? $technician->email
                    ?? null,
                'username' => $technician->username ?? null,
                'email' => $technician->email ?? null,
                'role' => $technician->role ?? null,
            ] : null,
        ];
    }

    /**
     * Set field sparepart kosong agar frontend tetap aman.
     */
    private function setEmptyPartUsageAttributes($report)
    {
        $report->setAttribute('part_usages', []);
        $report->setAttribute('partUsages', []);
        $report->setAttribute('spare_parts', []);
        $report->setAttribute('spareParts', []);

        return $report;
    }

    /**
     * Ambil repair history dengan aman.
     */
    private function getRepairHistory($damageReportId)
    {
        try {
            if (
                !Schema::hasTable('repairs') ||
                !Schema::hasColumn('repairs', 'damage_report_id')
            ) {
                return null;
            }

            $query = Repair::query();

            $repairModel = new Repair();
            $relations = [];

            if (method_exists($repairModel, 'technician')) {
                $relations[] = 'technician';
            }

            if (method_exists($repairModel, 'items')) {
                $relations[] = 'items.part';
            }

            if (!empty($relations)) {
                $query->with($relations);
            }

            return $query
                ->where('damage_report_id', $damageReportId)
                ->first();
        } catch (\Throwable $e) {
            Log::error('getRepairHistory error: ' . $e->getMessage());

            try {
                return Repair::where('damage_report_id', $damageReportId)->first();
            } catch (\Throwable $inner) {
                Log::error('getRepairHistory fallback error: ' . $inner->getMessage());
                return null;
            }
        }
    }

    /**
     * Ambil service booking yang sudah selesai.
     */
    private function getCompletedServiceBooking(DamageReport $damageReport)
    {
        try {
            if (
                !Schema::hasTable('service_bookings') ||
                !Schema::hasColumn('service_bookings', 'damage_report_id')
            ) {
                return null;
            }

            $query = ServiceBooking::query();

            $bookingModel = new ServiceBooking();

            if (method_exists($bookingModel, 'technician')) {
                $query->with(['technician']);
            }

            $query->where('damage_report_id', $damageReport->id)
                ->whereIn('status', [
                    'completed',
                    'finished',
                    'selesai',
                ]);

            if (Schema::hasColumn('service_bookings', 'completed_at')) {
                $query->latest('completed_at');
            }

            if (Schema::hasColumn('service_bookings', 'updated_at')) {
                $query->latest('updated_at');
            }

            return $query->first();
        } catch (\Throwable $e) {
            Log::error('getCompletedServiceBooking error: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Normalisasi status dari frontend ke status backend.
     */
    private function normalizeStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $status = strtolower(trim($status));
        $status = str_replace([' ', '-'], '_', $status);

        return match ($status) {
            /**
             * Laporan baru / booking approved tetap tampil sebagai reported.
             */
            'reported',
            'waiting',
            'pending',
            'requested',
            'menunggu',
            'dilaporkan',
            'approved',
            'scheduled',
            'rescheduled' => 'reported',

            /**
             * Damage report baru boleh proses jika teknisi sudah start job.
             */
            'ongoing',
            'in_progress',
            'progress',
            'on_progress',
            'diproses',
            'proses',
            'started',
            'job_started',
            'repair_started',
            'technician_started',
            'working' => 'proses',

            'on_hold',
            'waiting_parts',
            'menunggu_sparepart',
            'butuh_followup',
            'butuh_followup_admin' => 'butuh_followup_admin',

            'finished',
            'completed',
            'complete',
            'done',
            'closed',
            'selesai' => 'selesai',

            'fatal',
            'critical' => 'fatal',

            'approved_followup_admin',
            'followup_approved',
            'follow_up_approved' => 'approved_followup_admin',

            'reject',
            'rejected',
            'ditolak' => 'rejected',

            'cancel',
            'canceled',
            'cancelled',
            'dibatalkan' => 'canceled',

            default => $status,
        };
    }}