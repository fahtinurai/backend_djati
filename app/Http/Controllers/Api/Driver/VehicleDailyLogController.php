<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\VehicleAssignment;
use App\Models\VehicleDailyLog;
use Illuminate\Http\Request;

class VehicleDailyLogController extends Controller
{
    public function index(Request $request)
    {
        $driver = $request->user();

        $logs = VehicleDailyLog::with('vehicle')
            ->where('driver_id', $driver->id)
            ->latest('log_date')
            ->latest()
            ->get();

        return response()->json([
            'data' => $logs,
        ]);
    }

    public function store(Request $request)
    {
        $driver = $request->user();

        $assignment = VehicleAssignment::with('vehicle')
            ->where('driver_id', $driver->id)
            ->orderBy('assigned_at', 'desc')
            ->first();

        if (!$assignment || !$assignment->vehicle) {
            return response()->json([
                'message' => 'Driver belum memiliki kendaraan yang di-assign.',
            ], 403);
        }

        $validated = $request->validate([
            'log_date' => 'required|date',
            'shift' => 'nullable|string|max:50',
            'hour_meter_start' => 'required|numeric|min:0',
            'hour_meter_end' => 'required|numeric|min:0|gte:hour_meter_start',
            'fuel_liters' => 'required|numeric|min:0',
            'note' => 'nullable|string',
        ]);

        $log = VehicleDailyLog::create([
            'vehicle_id' => $assignment->vehicle_id,
            'driver_id' => $driver->id,
            'log_date' => $validated['log_date'],
            'shift' => $validated['shift'] ?? null,
            'hour_meter_start' => $validated['hour_meter_start'],
            'hour_meter_end' => $validated['hour_meter_end'],
            'fuel_liters' => $validated['fuel_liters'],
            'note' => $validated['note'] ?? null,
        ]);

        $log->load('vehicle');

        return response()->json([
            'message' => 'Daily unit log berhasil disimpan.',
            'data' => $log,
        ], 201);
    }

    public function show(Request $request, VehicleDailyLog $vehicleDailyLog)
    {
        $driver = $request->user();

        if ((int) $vehicleDailyLog->driver_id !== (int) $driver->id) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }

        $vehicleDailyLog->load('vehicle');

        return response()->json([
            'data' => $vehicleDailyLog,
        ]);
    }
}