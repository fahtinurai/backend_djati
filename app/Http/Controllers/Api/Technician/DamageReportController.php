<?php

namespace App\Http\Controllers\Api\Technician;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use App\Models\TechnicianResponse;
use Illuminate\Http\Request;
use App\Services\FcmService;
use App\Services\NodeEventPublisher;

class DamageReportController extends Controller
{
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

        // Normalisasi status: lowercase dan spasi jadi underscore
        $raw = $request->input('status');
        $status = $raw ? strtolower(str_replace(' ', '_', trim($raw))) : null; // string|null

        $includeDone = $request->boolean('include_done');

        if (!empty($status)) {
            if (in_array($status, ['menunggu', 'waiting'], true)) {
                $q->whereDoesntHave('latestTechnicianResponse');
            } else {
                // status lain → cek pada latestTechnicianResponse
                $q->whereHas('latestTechnicianResponse', function ($r) use ($status) {
                    $r->where('status', $status);
                });
            }

            return response()->json($q->get());
        }

        // Default: kalau include_done=true, tampilkan semua (termasuk selesai)
        if ($includeDone) {
            return response()->json($q->get());
        }

        // Kalau include_done=false: sembunyikan yang statusnya 'selesai'
        $q->where(function ($x) {
            $x->whereDoesntHave('latestTechnicianResponse')
              ->orWhereHas('latestTechnicianResponse', function ($r) {
                  $r->where('status', '!=', 'selesai');
              });
        });

        return response()->json($q->get());
    }

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
     * Teknisi memberikan respons + kirim FCM
     */
    public function respond(Request $request, DamageReport $damageReport, FcmService $fcm)
    {
        $technician = $request->user();

        $request->validate([
            'status' => 'required|in:proses,butuh_followup_admin,fatal,selesai',
            'note'   => 'nullable|string',
        ]);

        $response = TechnicianResponse::create([
            'damage_id'      => $damageReport->id,
            'technician_id'  => $technician->id,
            'status'         => $request->status,
            'note'           => $request->note,
        ]);

        $response->load('technician');

        // load driver & vehicle (buat notif)
        $damageReport->load(['driver', 'vehicle']);

        try {
            //  Notif ke DRIVER
            if ($damageReport->driver) {
                $fcm->sendToUser(
                    $damageReport->driver,
                    'Update Laporan Kendaraan',
                    'Status laporan kamu: ' . $request->status,
                    [
                        'type'      => 'damage_report',
                        'role'      => 'driver',
                        'report_id' => (string) $damageReport->id,
                        'status'    => (string) $request->status,
                    ]
                );
            }

            // Notif ke ADMIN kalau butuh follow-up
            if ($request->status === 'butuh_followup_admin') {
                $plate = $damageReport->vehicle->plate_number ?? '-';

                $fcm->sendToRole(
                    'admin',
                    'Butuh Follow-up Admin',
                    'Ada laporan butuh follow-up untuk kendaraan ' . $plate,
                    [
                        'type'      => 'damage_report',
                        'role'      => 'admin',
                        'report_id' => (string) $damageReport->id,
                        'status'    => (string) $request->status,
                    ]
                );
            }
        } catch (\Throwable $e) {
            // jangan bikin API gagal gara-gara notif
        }

        // Event lama tetap (UNTUK WEB ADMIN / LOG)
        NodeEventPublisher::publish('technician_response.created', [
            'technician_response_id' => $response->id,
            'damage_report_id'       => $damageReport->id,
            'technician_id'          => $technician->id,
            'status'                 => $response->status,
            'note'                   => $response->note,
            'created_at'             => $response->created_at,
        ], ['admin']);

        //  event khusus FOLLOWUP agar halaman Followups auto update
        if ($response->status === 'butuh_followup_admin') {
            NodeEventPublisher::publish('damage_report.followup_created', [
                'damage_report_id' => $damageReport->id,
                'status'           => $response->status,
                'technician_response_id' => $response->id,
                'technician_id'    => $technician->id,
                'updated_at'       => now(),
            ], ['admin']);
        }

        return response()->json([
            'message'  => 'Respons teknisi berhasil ditambahkan',
            'response' => $response,
        ], 201);
    }

    public function updateResponse(Request $request, TechnicianResponse $technicianResponse)
    {
        $technician = $request->user();

        if ($technicianResponse->technician_id !== $technician->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'status' => 'sometimes|in:proses,butuh_followup_admin,fatal,selesai',
            'note'   => 'nullable|string',
        ]);

        $technicianResponse->update($request->only(['status', 'note']));

        // load relasi minimal untuk event
        $technicianResponse->load('damageReport');

        //  Event lama tetap (UNTUK WEB ADMIN / LOG)
        NodeEventPublisher::publish('technician_response.updated', [
            'technician_response_id' => $technicianResponse->id,
            'damage_report_id'       => $technicianResponse->damageReport->id,
            'technician_id'          => $technicianResponse->technician_id,
            'status'                 => $technicianResponse->status,
            'note'                   => $technicianResponse->note,
            'updated_at'             => $technicianResponse->updated_at,
        ], ['admin']);

        // kalau status diubah jadi butuh_followup_admin, trigger followup_created juga
        if ($technicianResponse->status === 'butuh_followup_admin') {
            NodeEventPublisher::publish('damage_report.followup_created', [
                'damage_report_id' => $technicianResponse->damageReport->id,
                'status'           => $technicianResponse->status,
                'technician_response_id' => $technicianResponse->id,
                'technician_id'    => $technicianResponse->technician_id,
                'updated_at'       => $technicianResponse->updated_at,
            ], ['admin']);
        }

        return response()->json([
            'message'  => 'Respons teknisi berhasil diupdate',
            'response' => $technicianResponse,
        ]);
    }

    public function myResponses(Request $request)
    {
        $technician = $request->user();

        $responses = TechnicianResponse::with(['damageReport.vehicle', 'damageReport.driver'])
            ->where('technician_id', $technician->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($responses);
    }
}
