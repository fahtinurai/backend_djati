<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\DamageReport;
use App\Models\TechnicianReview;
use Illuminate\Http\Request;
use App\Services\FcmService;
use App\Services\NodeEventPublisher;

class TechnicianReviewController extends Controller
{
    /**
     * GET /api/driver/damage-reports/{damageReport}/review
     * Return:
     * - review object (kalau ada)
     * - null (kalau belum ada)
     */
    public function show(Request $request, DamageReport $damageReport)
    {
        $driver = $request->user();

        if ((int) $damageReport->driver_id !== (int) $driver->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $review = TechnicianReview::query()
            ->with([
                // ✅ FIX: users tidak punya kolom 'name'
                'technician:id,username',
            ])
            ->where('damage_report_id', (int) $damageReport->id)
            ->where('driver_id', (int) $driver->id)
            ->first();

        // kalau belum ada review -> return null (200)
        return response()->json($review);
    }

    /**
     * POST /api/driver/damage-reports/{damageReport}/review
     * Body: rating (1..5), review (optional)
     * Return: { message, data: reviewObject }
     */
    public function store(Request $request, DamageReport $damageReport, FcmService $fcm)
    {
        $driver = $request->user();

        if ((int) $damageReport->driver_id !== (int) $driver->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review' => ['nullable', 'string', 'max:1000'],
        ]);

        // ambil teknisi dari latestTechnicianResponse
        $damageReport->load(['latestTechnicianResponse', 'vehicle']);

        $latest = $damageReport->latestTechnicianResponse;
        if (! $latest) {
            return response()->json(['message' => 'Belum ada respon teknisi.'], 422);
        }

        // hanya boleh setelah selesai
        if (strtolower((string) $latest->status) !== 'selesai') {
            return response()->json(['message' => 'Rating hanya bisa setelah status selesai.'], 422);
        }

        $review = TechnicianReview::updateOrCreate(
            [
                'damage_report_id' => (int) $damageReport->id,
                'driver_id'        => (int) $driver->id,
            ],
            [
                'technician_id' => (int) $latest->technician_id,
                'rating'        => (int) $validated['rating'],
                'review'        => $validated['review'] ?? null,
                'reviewed_at'   => now(),
            ]
        );

        // ✅ FIX: users tidak punya kolom 'name'
        $review->load([
            'technician:id,username',
        ]);

        // notif teknisi (optional)
        try {
            if ($review->technician) {
                $plate = $damageReport->vehicle->plate_number ?? '-';
                $techLabel = $review->technician->username ?? 'Teknisi';

                $fcm->sendToUser(
                    $review->technician,
                    'Rating Baru',
                    $techLabel . ' mendapat rating baru untuk perbaikan kendaraan ' . $plate,
                    [
                        'type'      => 'technician_review',
                        'role'      => 'technician',
                        'report_id' => (string) $damageReport->id,
                        'review_id' => (string) $review->id,
                        'rating'    => (string) $review->rating,
                    ]
                );
            }
        } catch (\Throwable $e) {
            // jangan bikin API gagal
        }

        // event admin (optional)
        try {
            NodeEventPublisher::publish('technician_review.created', [
                'review_id'        => $review->id,
                'damage_report_id' => $review->damage_report_id,
                'technician_id'    => $review->technician_id,
                'driver_id'        => $review->driver_id,
                'rating'           => $review->rating,
                'reviewed_at'      => optional($review->reviewed_at)->toISOString(),
                'created_at'       => optional($review->created_at)->toISOString(),
            ], ['admin']);
        } catch (\Throwable $e) {}

        return response()->json([
            'message' => 'Review tersimpan',
            'data'    => $review,
        ], 201);
    }
}
