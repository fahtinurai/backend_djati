<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class VehicleController extends Controller
{
    /**
     * GET /vehicles
     */
    public function index()
    {
        $vehicles = Vehicle::orderBy('created_at', 'desc')->get();

        return response()->json($vehicles);
    }

    /**
     * POST /vehicles
     */
    public function store(Request $request)
    {
        $request->validate([
            'equipment_name' => 'required|string|max:255|unique:vehicles,equipment_name',
            'plate_number'   => 'required|string|max:255|unique:vehicles,plate_number',
            'brand'          => 'nullable|string|max:255',
            'model'          => 'nullable|string|max:255',
            'year'           => 'nullable|integer',
        ]);

        $vehicle = Vehicle::create([
            'equipment_name' => $request->equipment_name,
            'brand'          => $request->brand,
            'model'          => $request->model,
            'plate_number'   => $request->plate_number,
            'year'           => $request->year,
        ]);

        /* ============================
           🔴 REALTIME PUBLISH (NODE)
        ============================ */
        try {
            Http::withHeaders([
                'x-service-key' => config('services.realtime.key'),
            ])->post(
                rtrim(config('services.realtime.url'), '/') . '/events/publish',
                [
                    'event'    => 'vehicle.created',
                    'channels' => ['admin'],
                    'data'     => [
                        'id'             => $vehicle->id,
                        'equipment_name' => $vehicle->equipment_name, // ✅ tambahan
                        'brand'          => $vehicle->brand,
                        'model'          => $vehicle->model,
                        'plate_number'   => $vehicle->plate_number,
                        'year'           => $vehicle->year,
                        'created_at'     => $vehicle->created_at,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            logger()->error('Realtime vehicle.created failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json($vehicle, 201);
    }

    /**
     * PUT /vehicles/{vehicle}
     */
    public function update(Request $request, Vehicle $vehicle)
    {
        $request->validate([
            'equipment_name' => 'sometimes|string|max:255|unique:vehicles,equipment_name,' . $vehicle->id,
            'plate_number'   => 'sometimes|string|max:255|unique:vehicles,plate_number,' . $vehicle->id,
            'brand'          => 'sometimes|nullable|string|max:255',
            'model'          => 'sometimes|nullable|string|max:255',
            'year'           => 'sometimes|nullable|integer',
        ]);

        $vehicle->update(
            $request->only([
                'equipment_name',
                'brand',
                'model',
                'plate_number',
                'year',
            ])
        );

        return response()->json($vehicle);
    }

    /**
     * DELETE /vehicles/{vehicle}
     */
    public function destroy(Vehicle $vehicle)
    {
        $vehicle->delete();

        return response()->json([
            'message' => 'Kendaraan dihapus',
        ]);
    }
}