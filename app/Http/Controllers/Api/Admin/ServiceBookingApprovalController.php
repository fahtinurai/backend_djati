<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceBooking;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

        if ($status && $status !== 'all') {
            $q->where('status', $status);
        }

        return response()->json($q->get());
    }

    /*
    |-----------------------------------------
    | GET TECHNICIANS
    |-----------------------------------------
    */
    public function technicians()
    {
        $roles = [
            'technician',
            'mekanik',
            'teknisi',
            'mechanic',
        ];

        $q = User::query();

        if (Schema::hasColumn('users', 'role')) {
            $q->whereIn('role', $roles);
        } elseif (Schema::hasColumn('users', 'user_role')) {
            $q->whereIn('user_role', $roles);
        } elseif (Schema::hasColumn('users', 'role_name')) {
            $q->whereIn('role_name', $roles);
        } elseif (method_exists(User::class, 'role')) {
            $q->whereHas('role', function ($query) use ($roles) {
                $query
                    ->whereIn('name', $roles)
                    ->orWhereIn('slug', $roles);
            })->with('role');
        } elseif (method_exists(User::class, 'roles')) {
            $q->whereHas('roles', function ($query) use ($roles) {
                $query
                    ->whereIn('name', $roles)
                    ->orWhereIn('slug', $roles);
            })->with('roles');
        } else {
            return response()->json([
                'data' => [],
            ]);
        }

        $technicians = $q
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                $role = null;

                if (isset($user->role) && is_string($user->role)) {
                    $role = $user->role;
                } elseif (isset($user->role) && is_object($user->role)) {
                    $role =
                        $user->role->name ??
                        $user->role->slug ??
                        null;
                } elseif (isset($user->roles) && $user->roles->count()) {
                    $role =
                        $user->roles->first()->name ??
                        $user->roles->first()->slug ??
                        null;
                } elseif (isset($user->user_role)) {
                    $role = $user->user_role;
                } elseif (isset($user->role_name)) {
                    $role = $user->role_name;
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name ?? $user->username ?? 'Technician ' . $user->id,
                    'username' => $user->username ?? $user->name ?? null,
                    'email' => $user->email ?? null,
                    'role' => $role ?? 'technician',
                ];
            })
            ->values();

        return response()->json([
            'data' => $technicians,
        ]);
    }

    /*
    |-----------------------------------------
    | NORMALIZE SCHEDULE RANGE
    |-----------------------------------------
    */
    private function normalizeScheduleRange($scheduledAt, $estimatedFinishAt = null)
    {
        $start = strtotime($scheduledAt);

        if (!$start) {
            return [
                'start' => null,
                'end' => null,
            ];
        }

        $end = $estimatedFinishAt
            ? strtotime($estimatedFinishAt)
            : null;

        /*
        |-----------------------------------------
        | Jika estimated_finish_at kosong,
        | default durasi dianggap 1 jam.
        |-----------------------------------------
        */
        if (!$end || $end <= $start) {
            $end = strtotime('+1 hour', $start);
        }

        return [
            'start' => date('Y-m-d H:i:s', $start),
            'end' => date('Y-m-d H:i:s', $end),
        ];
    }

    /*
    |-----------------------------------------
    | CHECK SCHEDULE CONFLICT (BY DATE + TIME)
    |-----------------------------------------
    */
    private function hasScheduleConflict(
        $technicianId,
        $scheduledAt,
        $estimatedFinishAt = null,
        $bookingId = null
    ) {
        if (!$technicianId || !$scheduledAt) {
            return false;
        }

        $range = $this->normalizeScheduleRange($scheduledAt, $estimatedFinishAt);

        if (!$range['start'] || !$range['end']) {
            return false;
        }

        /*
        |-----------------------------------------
        | Logika bentrok:
        |
        | Jadwal baru bentrok jika:
        | existing_start < new_end
        | DAN
        | existing_end > new_start
        |
        | Jika existing estimated_finish_at kosong,
        | dianggap existing_end = existing scheduled_at + 1 jam.
        |-----------------------------------------
        */
        return ServiceBooking::where('technician_id', $technicianId)
            ->when($bookingId, function ($query) use ($bookingId) {
                $query->where('id', '!=', $bookingId);
            })
            ->whereIn('status', [
                'approved',
                'rescheduled',
                'in_progress',
            ])
            ->whereNotNull('scheduled_at')
            ->where(function ($query) use ($range) {
                $query->whereRaw(
                    'scheduled_at < ?',
                    [$range['end']]
                )
                ->whereRaw(
                    'COALESCE(estimated_finish_at, DATE_ADD(scheduled_at, INTERVAL 1 HOUR)) > ?',
                    [$range['start']]
                );
            })
            ->exists();
    }

    /*
    |-----------------------------------------
    | APPROVE + ASSIGN TECHNICIAN
    |-----------------------------------------
    */
    public function approve(Request $request, ServiceBooking $booking)
    {
        $request->validate([
            'scheduled_at' => 'required|date',
            'estimated_finish_at' => 'nullable|date|after:scheduled_at',
            'technician_id' => 'required|exists:users,id',
            'priority' => 'nullable|in:low,medium,high,critical',
            'note_admin' => 'nullable|string|max:1000',
        ]);

        if (!in_array($booking->status, ['requested', 'rescheduled'], true)) {
            return response()->json(['message' => 'Status tidak valid'], 422);
        }

        $technicianId = $request->technician_id;

        $range = $this->normalizeScheduleRange(
            $request->scheduled_at,
            $request->estimated_finish_at
        );

        /*
        |-----------------------------------------
        | CEK CONFLICT BERDASARKAN JAM
        |-----------------------------------------
        */
        if (
            $this->hasScheduleConflict(
                $technicianId,
                $range['start'],
                $range['end'],
                $booking->id
            )
        ) {
            return response()->json([
                'message' => 'Teknisi sudah memiliki jadwal pada jam tersebut. Silakan pilih jam lain.'
            ], 409);
        }

        $booking->update([
            'status' => 'approved',
            'scheduled_at' => $range['start'],
            'estimated_finish_at' => $range['end'],
            'technician_id' => $technicianId,
            'priority' => $request->priority ?? 'medium',
            'note_admin' => $request->note_admin,
        ]);

        $booking->refresh()->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        $this->notify($booking, 'approved');

        return response()->json([
            'message' => 'Booking di-approve',
            'data' => $booking,
        ]);
    }

    /*
    |-----------------------------------------
    | RESCHEDULE
    |-----------------------------------------
    */
    public function reschedule(Request $request, ServiceBooking $booking)
    {
        $request->validate([
            'scheduled_at' => 'required|date',
            'estimated_finish_at' => 'nullable|date|after:scheduled_at',
            'technician_id' => 'nullable|exists:users,id',
            'priority' => 'nullable|in:low,medium,high,critical',
            'note_admin' => 'nullable|string|max:1000',
        ]);

        if (!in_array($booking->status, ['approved', 'rescheduled'], true)) {
            return response()->json(['message' => 'Tidak bisa reschedule'], 422);
        }

        $technicianId = $request->technician_id ?? $booking->technician_id;

        if (!$technicianId) {
            return response()->json([
                'message' => 'Technician wajib dipilih'
            ], 422);
        }

        $range = $this->normalizeScheduleRange(
            $request->scheduled_at,
            $request->estimated_finish_at
        );

        /*
        |-----------------------------------------
        | CEK CONFLICT BERDASARKAN JAM
        |-----------------------------------------
        */
        if (
            $this->hasScheduleConflict(
                $technicianId,
                $range['start'],
                $range['end'],
                $booking->id
            )
        ) {
            return response()->json([
                'message' => 'Jadwal teknisi bentrok pada jam tersebut. Silakan pilih jam lain.'
            ], 409);
        }

        $booking->update([
            'status' => 'rescheduled',
            'scheduled_at' => $range['start'],
            'estimated_finish_at' => $range['end'],
            'technician_id' => $technicianId,
            'priority' => $request->priority ?? $booking->priority ?? 'medium',
            'note_admin' => $request->note_admin ?? $booking->note_admin,
        ]);

        $booking->refresh()->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        $this->notify($booking, 'rescheduled');

        return response()->json([
            'message' => 'Booking di-reschedule',
            'data' => $booking,
        ]);
    }

    /*
    |-----------------------------------------
    | START JOB (NEW FLOW POINT 3)
    |-----------------------------------------
    */
    public function startJob(ServiceBooking $booking)
    {
        if (!in_array($booking->status, ['approved', 'rescheduled'])) {
            return response()->json(['message' => 'Tidak bisa start job'], 422);
        }

        if (!$booking->technician_id) {
            return response()->json([
                'message' => 'Booking belum memiliki teknisi'
            ], 422);
        }

        if ((int) $booking->technician_id !== (int) auth()->id()) {
            return response()->json([
                'message' => 'Booking ini bukan milik teknisi yang sedang login'
            ], 403);
        }

        $booking->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        $booking->refresh()->load([
            'damageReport.vehicle',
            'damageReport.driver',
            'vehicle',
            'driver',
            'technician',
        ]);

        $this->notify($booking, 'started');

        return response()->json([
            'message' => 'Job dimulai',
            'data' => $booking,
        ]);
    }

    /*
    |-----------------------------------------
    | CANCEL
    |-----------------------------------------
    */
    public function cancel(Request $request, ServiceBooking $booking)
    {
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

        $this->notify($booking, 'canceled');

        return response()->json([
            'message' => 'Booking dibatalkan',
            'data' => $booking,
        ]);
    }

    /*
    |-----------------------------------------
    | NOTIFICATION (REUSE - TIDAK UBAH FLOW LAMA)
    |-----------------------------------------
    */
    private function notify(ServiceBooking $booking, string $type)
    {
        try {
            $fcm = app(\App\Services\FcmService::class);
            $fs = app(\App\Services\FirestoreService::class);

            $report = $booking->damageReport;

            if ($report && $report->driver) {

                $plate = $report->vehicle?->plate_number ?? '-';

                $fs->pushUserNotification($report->driver->id, [
                    'title' => "Booking {$type}",
                    'body' => "Status booking {$plate} berubah menjadi {$booking->status}",
                    'type' => 'service_booking',
                    'role' => 'driver',
                    'data' => [
                        'booking_id' => $booking->id,
                        'report_id' => $report->id,
                        'status' => $booking->status,
                    ],
                ]);

                $fcm->sendToUser(
                    $report->driver,
                    "Booking {$type}",
                    "Status booking {$plate} berubah menjadi {$booking->status}",
                    []
                );
            }

            if ($booking->technician) {

                $plate =
                    $booking->vehicle?->plate_number ??
                    $booking->damageReport?->vehicle?->plate_number ??
                    '-';

                $fs->pushUserNotification($booking->technician->id, [
                    'title' => "Maintenance {$type}",
                    'body' => "Jadwal maintenance {$plate} berubah menjadi {$booking->status}",
                    'type' => 'service_booking',
                    'role' => 'technician',
                    'data' => [
                        'booking_id' => $booking->id,
                        'status' => $booking->status,
                        'technician_id' => $booking->technician_id,
                    ],
                ]);

                $fcm->sendToUser(
                    $booking->technician,
                    "Maintenance {$type}",
                    "Jadwal maintenance {$plate} berubah menjadi {$booking->status}",
                    []
                );
            }

        } catch (\Throwable $e) {
            Log::warning("Notification error", [
                'error' => $e->getMessage()
            ]);
        }

        try {
            NodeEventPublisher::publish("service_booking.{$type}", [
                'booking_id' => $booking->id,
                'status' => $booking->status,
                'technician_id' => $booking->technician_id,
            ], ['admin', 'driver', 'technician']);
        } catch (\Throwable $e) {
            Log::warning("Node event error", [
                'error' => $e->getMessage()
            ]);
        }
    }
}