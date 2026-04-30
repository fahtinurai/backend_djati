<?php

namespace App\Http\Controllers\Api\Technician;

use App\Http\Controllers\Controller;
use App\Models\TechnicianReview;
use Illuminate\Http\Request;

class TechnicianReviewController extends Controller
{
    /**
     * GET /api/technician/reviews
     *
     * Response:
     * {
     *   "summary": { "avg_rating": 4.25, "total_reviews": 12 },
     *   "data": { ... paginator ..., "data": [ ...items... ] }
     * }
     *
     * Compatible with:
     * - api_service.dart getTechnicianReviews(): expects summary + data(paginator)
     * - technician_home.dart Reviews tab: show avg & total + list of reviews
     * - models.dart ApiTechnicianReview: expects rating, review, created_at, driver, technician, damageReport.vehicle.plate_number
     */
    public function index(Request $request)
    {
        $tech = $request->user();

        // pagination size (opsional)
        $perPage = (int) $request->query('per_page', 20);
        if ($perPage <= 0) $perPage = 20;
        if ($perPage > 100) $perPage = 100;

        $q = TechnicianReview::query()
            ->with([
                // ✅ FIX: users tidak punya kolom 'name'
                'driver:id,username',
                'technician:id,username',
                'damageReport:id,vehicle_id,driver_id,created_at',
                'damageReport.vehicle:id,plate_number,brand,model',
            ])
            ->where('technician_id', (int) $tech->id)
            // urutkan paling baru (kalau ada reviewed_at pakai itu, else created_at)
            ->orderByRaw('COALESCE(reviewed_at, created_at) DESC');

        $items = $q->paginate($perPage);

        // summary
        $base = TechnicianReview::query()
            ->where('technician_id', (int) $tech->id);

        $avg = $base->avg('rating');
        $count = $base->count();

        return response()->json([
            'summary' => [
                'avg_rating' => $avg ? round((float) $avg, 2) : 0,
                'total_reviews' => (int) $count,
            ],
            'data' => $items,
        ]);
    }

    /**
     * GET /api/technician/reviews/{review}
     * Detail 1 review (harus milik teknisi login)
     *
     * Response: review object langsung
     */
    public function show(Request $request, TechnicianReview $review)
    {
        $tech = $request->user();

        if ((int) $review->technician_id !== (int) $tech->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $review->load([
            // ✅ FIX: users tidak punya kolom 'name'
            'driver:id,username',
            'technician:id,username',
            'damageReport.vehicle:id,plate_number,brand,model',
        ]);

        return response()->json($review);
    }
}
