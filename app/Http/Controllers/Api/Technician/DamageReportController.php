<?php

namespace App\Http\Controllers\Api\Technician;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
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
     * Default:
     * - Menampilkan laporan yang belum selesai
     *
     * Query opsional:
     * - ?include_done=true
     * - ?status=reported
     * - ?status=ongoing
     * - ?status=on_hold
     * - ?status=finished
     */
    public function index(Request $request)
    {
        $q = DamageReport::query()
            ->with([
                'vehicle',
                'driver',
                'latestTechnicianResponse.technician',
                'technicianResponses.technician',
            ])
            ->latest();

        $rawStatus = $request->input('status');
        $status = $rawStatus
            ? $this->normalizeStatus($rawStatus)
            : null;

        $includeDone = $request->boolean('include_done');

        if (!empty($status)) {
            if ($status === 'menunggu') {
                $q->whereDoesntHave('latestTechnicianResponse');
            } else {
                $q->whereHas('latestTechnicianResponse', function ($r) use ($status) {
                    $r->where('status', $status);
                });
            }

            return response()->json($q->get());
        }

        if (!$includeDone) {
            $q->where(function ($x) {
                $x->whereDoesntHave('latestTechnicianResponse')
                    ->orWhereHas('latestTechnicianResponse', function ($r) {
                        $r->where('status', '!=', 'selesai');
                    });
            });
        }

        return response()->json($q->get());
    }

    /**
     * Detail laporan kerusakan.
     */
    public function show(DamageReport $damageReport)
    {
        $damageReport->load([
            'vehicle',
            'driver',
            'technicianResponses.technician',
            'latestTechnicianResponse.technician',
        ]);

        return response()->json($damageReport);
    }

    /**
     * Teknisi memberi respons / update status laporan kerusakan.
     *
     * Status dari Flutter:
     * - Ongoing  -> proses
     * - On Hold  -> butuh_followup_admin
     * - Finished -> selesai
     * - Fatal    -> fatal
     *
     * Status backend:
     * - proses
     * - butuh_followup_admin
     * - fatal
     * - selesai
     *
     * Penting:
     * FcmService tidak dimasukkan sebagai parameter method,
     * karena kalau FIREBASE_CREDENTIALS belum di-set, Laravel akan gagal
     * sebelum data response teknisi sempat disimpan.
     */
    public function respond(Request $request, DamageReport $damageReport)
    {
        $technician = $request->user();

        Log::info('TECHNICIAN RESPOND MASUK', [
            'user_id' => optional($technician)->id,
            'damage_report_id' => $damageReport->id,
            'request_all' => $request->all(),
        ]);

        $request->validate([
            'status' => 'required|string',
            'note'   => 'nullable|string',

            // Opsional untuk KPI, aman jika kolom belum ada.
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

        /**
         * Sinkronkan fallback status di damage_reports jika kolom status ada.
         * UI utama tetap bisa membaca latest_technician_response,
         * tetapi kolom ini berguna sebagai backup.
         */
        if (Schema::hasColumn('damage_reports', 'status')) {
            $damageReport->update([
                'status' => $status,
            ]);
        }

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
        ]);

        /**
         * FCM Notification.
         *
         * FCM dibuat di dalam try supaya jika FIREBASE_CREDENTIALS belum ada,
         * proses simpan response teknisi tetap berhasil.
         */
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
                        'status'    => (string) $status,
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('FCM gagal / dilewati saat technician respond', [
                'message' => $e->getMessage(),
            ]);
        }

        /**
         * Node event ke admin/driver.
         */
        try {
            NodeEventPublisher::publish('technician_response.created', [
                'technician_response_id' => $response->id,
                'damage_report_id'       => $damageReport->id,
                'technician_id'          => $technician->id,
                'technician_name'        => $technician->name ?? $technician->username ?? null,
                'vehicle_id'             => $damageReport->vehicle_id,
                'equipment_name'         => $damageReport->vehicle?->equipment_name,
                'plate_number'           => $damageReport->vehicle?->plate_number,
                'driver_id'              => $damageReport->driver_id,
                'driver_name'            => $damageReport->driver?->name ?? $damageReport->driver?->username,
                'status'                 => $response->status,
                'status_label'           => $this->statusLabelForDriver($response->status),
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

        $request->validate([
            'status' => 'sometimes|string',
            'note'   => 'nullable|string',

            // Opsional untuk KPI.
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

        /**
         * Jika response yang diupdate adalah response terbaru,
         * sinkronkan fallback status di damage_reports.
         */
        $technicianResponse->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'technician',
        ]);

        if (
            isset($data['status']) &&
            $technicianResponse->damageReport &&
            Schema::hasColumn('damage_reports', 'status')
        ) {
            $technicianResponse->damageReport->update([
                'status' => $data['status'],
            ]);
        }

        try {
            NodeEventPublisher::publish('technician_response.updated', [
                'technician_response_id' => $technicianResponse->id,
                'damage_report_id'       => $technicianResponse->damageReport?->id,
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