<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use App\Models\Repair;
use App\Models\ServiceBooking;
use App\Models\TechnicianResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\NodeEventPublisher;

class DamageReportController extends Controller
{
    /**
     * Admin melihat semua laporan kerusakan.
     *
     * Support query:
     * - ?status=menunggu
     * - ?status=proses
     * - ?status=butuh_followup_admin
     * - ?status=selesai
     * - ?status=fatal
     * - ?search=keyword
     * - ?limit=5
     */
    public function index(Request $request)
    {
        $q = DamageReport::query()
            ->with([
                'vehicle',
                'driver',
                'technicianResponses.technician',
                'latestTechnicianResponse.technician',
                'booking',
            ])
            ->orderBy('created_at', 'desc');

        /*
        |--------------------------------------------------------------------------
        | FILTER STATUS
        |--------------------------------------------------------------------------
        | Frontend mengirim:
        | - selesai untuk Completed
        | - proses untuk In Progress
        | - butuh_followup_admin untuk Waiting Parts
        | - menunggu untuk Reported
        */
        if ($request->filled('status')) {
            $status = $this->normalizeStatus($request->query('status'));

            if ($status === 'menunggu') {
                $q->where(function ($query) {
                    $query->whereDoesntHave('latestTechnicianResponse')
                        ->orWhere('status', 'menunggu')
                        ->orWhereNull('status');
                });
            } elseif ($status === 'selesai') {
                /*
                |--------------------------------------------------------------------------
                | SUPPORT FLOW LAMA + FLOW BARU
                |--------------------------------------------------------------------------
                | Flow lama:
                | - technician_responses.status = selesai
                |
                | Flow baru maintenance scheduling:
                | - service_bookings.status = completed / finished / selesai
                */
                $q->where(function ($query) {
                    $query->whereHas('latestTechnicianResponse', function ($response) {
                        $response->where('status', 'selesai');
                    })
                    ->orWhereHas('booking', function ($booking) {
                        $booking->whereIn('status', [
                            'completed',
                            'finished',
                            'selesai',
                        ]);
                    });
                });
            } else {
                $q->whereHas('latestTechnicianResponse', function ($response) use ($status) {
                    $response->where('status', $status);
                });
            }
        }

        /*
        |--------------------------------------------------------------------------
        | SEARCH
        |--------------------------------------------------------------------------
        | Cari berdasarkan:
        | - nama equipment
        | - plate number
        | - username driver
        | - damage type
        | - description
        */
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
                        $driver->where('username', 'like', "%{$search}%");
                    });
            });
        }

        /*
        |--------------------------------------------------------------------------
        | LIMIT
        |--------------------------------------------------------------------------
        */
        if ($request->filled('limit')) {
            $limit = (int) $request->query('limit');

            if ($limit > 0) {
                $q->limit($limit);
            }
        }

        $reports = $q->get();

        $reports = $this->attachRepairHistoryInfo($reports);

        return response()->json($reports);
    }

    /**
     * Admin melihat detail laporan kerusakan
     */
    public function show(DamageReport $damageReport)
    {
        $damageReport->load([
            'vehicle',
            'driver',
            'technicianResponses.technician',
            'latestTechnicianResponse.technician',
            'booking',
        ]);

        $repair = Repair::with([
                'technician',
                'items.part',
            ])
            ->where('damage_report_id', $damageReport->id)
            ->first();

        $damageReport->setAttribute('repair_history_saved', $repair ? true : false);
        $damageReport->setAttribute('repair_history', $repair);

        return response()->json($damageReport);
    }

    /**
     * Admin melihat laporan yang butuh follow-up
     */
    public function followUps()
    {
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

        $reports = $this->attachRepairHistoryInfo($reports);

        return response()->json($reports);
    }

    /**
     * Admin melihat semua laporan teknisi yang sudah selesai.
     *
     * Ini untuk halaman admin repair history / finished repair.
     */
    public function finishedRepairs()
    {
        $reports = DamageReport::with([
                'vehicle',
                'driver',
                'technicianResponses.technician',
                'latestTechnicianResponse.technician',
                'booking',
                'review',
            ])
            ->where(function ($query) {
                /*
                |--------------------------------------------------------------------------
                | SUPPORT FLOW LAMA + FLOW BARU
                |--------------------------------------------------------------------------
                | Flow lama:
                | - technician_responses.status = selesai
                |
                | Flow baru:
                | - service_bookings.status = completed / finished / selesai
                */
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

        return response()->json($reports);
    }

    /**
     * Admin menyimpan finished repair history dari hasil teknisi.
     *
     * Alur:
     * - Teknisi update laporan menjadi status selesai.
     * - Data status selesai tersimpan di technician_responses.
     * - Admin memanggil endpoint ini untuk menyimpan hasil tersebut ke tabel repairs.
     * - Repair dibuat finalized=true sebagai history final admin.
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

        /*
        |--------------------------------------------------------------------------
        | SUPPORT FLOW BARU SERVICE BOOKING
        |--------------------------------------------------------------------------
        | Jika teknisi menyelesaikan pekerjaan dari halaman maintenance scheduling,
        | status selesai tersimpan di service_bookings.status = completed.
        */
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
                /*
                |--------------------------------------------------------------------------
                | Jika flow baru sudah completed tapi technician_response belum ada,
                | buat bridge/audit response supaya flow lama tetap kompatibel.
                |--------------------------------------------------------------------------
                */
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

                /*
                |--------------------------------------------------------------------------
                | Pastikan fallback status damage_reports juga selesai.
                |--------------------------------------------------------------------------
                */
                $damageReport->update([
                    'status' => 'selesai',
                ]);

                /*
                |--------------------------------------------------------------------------
                | Tentukan data teknisi dan action dari flow lama / flow baru.
                |--------------------------------------------------------------------------
                */
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

                /*
                |--------------------------------------------------------------------------
                | Simpan / update repair history admin.
                |--------------------------------------------------------------------------
                */
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
            return response()->json([
                'message' => 'Gagal menyimpan finished repair history.',
                'error' => $e->getMessage(),
            ], 500);
        }

        $repair->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'technician',
            'items.part',
        ]);

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
            \Log::error('NodeEventPublisher repair.finished_saved error: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Finished repair history berhasil disimpan ke admin.',
            'damage_report' => $damageReport,
            'latest_technician_response' => $damageReport->latestTechnicianResponse,
            'completed_service_booking' => $completedBooking,
            'repair' => $repair,
        ], 201);
    }

    /**
     * Admin approve follow-up:
     * - update damage_reports.status = approved_followup_admin
     * - buat audit trail technician_responses (status approved_followup_admin)
     * - auto create repair draft (firstOrCreate)
     */
    public function markAsCompleted(Request $request, DamageReport $damageReport)
    {
        $request->validate([
            'admin_note' => 'nullable|string',
        ]);

        $damageReport->load(['vehicle', 'latestTechnicianResponse']);

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
            DB::transaction(function () use ($request, $damageReport) {

                // 1) Update status damage report
                $damageReport->update([
                    'status' => 'approved_followup_admin',
                ]);

                // 2) Audit trail
                $damageReport->technicianResponses()->create([
                    'damage_id'      => $damageReport->id,
                    'technician_id'  => null,
                    'status'         => 'approved_followup_admin',
                    'note'           => $request->admin_note ?? 'Approved by admin',
                ]);

                // 3) Auto create repair draft
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
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }

        // ambil repair yang barusan dibuat
        $repair = Repair::where('damage_report_id', $damageReport->id)->first();

        // realtime events
        try {
            NodeEventPublisher::publish('damage_report.followup_approved', [
                'damage_report_id' => $damageReport->id,
                'status'           => 'approved_followup_admin',
                'admin_note'       => $request->admin_note,
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
            \Log::error('NodeEventPublisher error: ' . $e->getMessage());
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

        return response()->json([
            'message'       => 'Follow-up disetujui & repair draft dibuat',
            'damage_report' => $damageReport,
            'repair'        => $repair,
        ]);
    }

    /**
     * Tambahkan informasi repair history ke setiap damage report.
     *
     * Ini dipakai frontend untuk:
     * - menyembunyikan tombol Save to Repair History jika sudah pernah disimpan
     * - menampilkan status saved
     */
    private function attachRepairHistoryInfo($reports)
    {
        $ids = $reports->pluck('id')->filter()->values();

        if ($ids->isEmpty()) {
            return $reports;
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
    }

    /**
     * Ambil service booking yang sudah selesai untuk flow maintenance scheduling baru.
     */
    private function getCompletedServiceBooking(DamageReport $damageReport)
    {
        return ServiceBooking::with([
                'technician',
            ])
            ->where('damage_report_id', $damageReport->id)
            ->whereIn('status', [
                'completed',
                'finished',
                'selesai',
            ])
            ->latest('completed_at')
            ->latest('updated_at')
            ->first();
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

            'approved_followup_admin',
            'followup_approved',
            'follow_up_approved' => 'approved_followup_admin',

            default => $status,
        };
    }
}