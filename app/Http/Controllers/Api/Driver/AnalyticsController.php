<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\ServiceBooking;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $driver = $request->user();

        $assignment = VehicleAssignment::with('vehicle')
            ->where('driver_id', $driver->id)
            ->orderBy('assigned_at', 'desc')
            ->first();

        if (!$assignment || !$assignment->vehicle) {
            return response()->json([
                'message' => 'Driver belum memiliki kendaraan yang di-assign.',
                'data' => [
                    'vehicle' => null,
                    'total_hour_meter' => 0,
                    'annual_service_count' => 0,
                    'fuel_consumption_liters' => 0,
                ],
            ]);
        }

        $vehicle = $assignment->vehicle;

        $annualServiceCount = ServiceBooking::where('driver_id', $driver->id)
            ->where('vehicle_id', $vehicle->id)
            ->whereYear('created_at', now()->year)
            ->count();

        return response()->json([
            'data' => [
                'vehicle' => [
                    'id' => $vehicle->id,
                    'equipment_name' => $vehicle->equipment_name,
                    'plate_number' => $vehicle->plate_number,
                    'next_service_at' => $vehicle->next_service_at,
                    'reminder_enabled' => $vehicle->reminder_enabled,
                    'reminder_days_before' => $vehicle->reminder_days_before,
                ],
                'total_hour_meter' => (float) ($vehicle->hour_meter ?? 0),
                'annual_service_count' => $annualServiceCount,
                'fuel_consumption_liters' => (float) ($vehicle->fuel_consumption_liters ?? 0),
            ],
        ]);
    }
}