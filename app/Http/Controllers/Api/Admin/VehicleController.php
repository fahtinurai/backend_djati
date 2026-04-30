<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

//Fathian Alfiana Rahman menambahkan VehicleController.php untuk menambahkan data kendaraan

class VehicleController extends Controller
{
    /**
     * GET /vehicles
     */
    public function index()
    {
        return response()->json(Vehicle::all());
    }

    /**
     * POST /vehicles
     */
    public function store(Request $request)
    {
        $request->validate([
            'plate_number' => 'required|unique:vehicles',
            'brand'        => 'nullable|string',
            'model'        => 'nullable|string',
            'year'         => 'nullable|integer',
        ]);

        $vehicle = Vehicle::create([
            'brand'        => $request->brand,
            'model'        => $request->model,
            'plate_number' => $request->plate_number,
            'year'         => $request->year,
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
                        'id'           => $vehicle->id,
                        'brand'        => $vehicle->brand,
                        'model'        => $vehicle->model,
                        'plate_number' => $vehicle->plate_number,
                        'year'         => $vehicle->year,
                        'created_at'   => $vehicle->created_at,
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
            'plate_number' => 'sometimes|unique:vehicles,plate_number,' . $vehicle->id,
        ]);

        $vehicle->update(
            $request->only('brand', 'model', 'plate_number', 'year')
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
