<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;
use App\Services\NodeEventPublisher;

class VehicleAssignmentController extends Controller
{
    public function index()
    {
        $assignments = VehicleAssignment::with(['vehicle', 'driver'])
            ->orderBy('assigned_at', 'desc')
            ->get();

        return response()->json([
            'data' => $assignments
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'driver_id'  => 'required|exists:users,id',
        ]);

        $driver = User::findOrFail($request->driver_id);

        if ($driver->role !== 'driver') {
            return response()->json([
                'message' => 'User bukan driver'
            ], 400);
        }

        $vehicleAssigned = VehicleAssignment::where('vehicle_id', $request->vehicle_id)
            ->first();

        if ($vehicleAssigned) {
            return response()->json([
                'message' => 'Kendaraan sudah di-assign ke driver lain'
            ], 400);
        }

        $driverAssigned = VehicleAssignment::where('driver_id', $request->driver_id)
            ->first();

        if ($driverAssigned) {
            return response()->json([
                'message' => 'Driver sudah memiliki kendaraan'
            ], 400);
        }

        $assignment = VehicleAssignment::create([
            'vehicle_id'  => $request->vehicle_id,
            'driver_id'   => $request->driver_id,
            'assigned_at' => now(),
        ]);

        $assignment->load(['vehicle', 'driver']);

        NodeEventPublisher::publish('assignment.created', [
            'assignment_id'  => $assignment->id,
            'vehicle_id'     => $assignment->vehicle_id,
            'equipment_name' => $assignment->vehicle?->equipment_name,
            'plate_number'   => $assignment->vehicle?->plate_number,
            'driver_id'      => $assignment->driver_id,
            'driver_name'    => $assignment->driver?->name,
            'assigned_at'    => $assignment->assigned_at,
        ], ['admin', 'driver']);

        return response()->json([
            'message' => 'Kendaraan berhasil di-assign ke driver',
            'data'    => $assignment
        ], 201);
    }

    public function myVehicle(Request $request)
    {
        $assignment = VehicleAssignment::with(['vehicle', 'driver'])
            ->where('driver_id', $request->user()->id)
            ->first();

        if (!$assignment) {
            return response()->json([
                'message' => 'Belum ada kendaraan yang di-assign'
            ], 404);
        }

        return response()->json([
            'data' => $assignment
        ]);
    }

    public function show(VehicleAssignment $vehicleAssignment)
    {
        $vehicleAssignment->load(['vehicle', 'driver']);

        return response()->json([
            'data' => $vehicleAssignment
        ]);
    }

    public function destroy(VehicleAssignment $vehicleAssignment)
    {
        $vehicleAssignment->load(['vehicle', 'driver']);

        $payload = [
            'assignment_id'  => $vehicleAssignment->id,
            'vehicle_id'     => $vehicleAssignment->vehicle_id,
            'equipment_name' => $vehicleAssignment->vehicle?->equipment_name,
            'plate_number'   => $vehicleAssignment->vehicle?->plate_number,
            'driver_id'      => $vehicleAssignment->driver_id,
            'driver_name'    => $vehicleAssignment->driver?->name,
        ];

        $vehicleAssignment->delete();

        NodeEventPublisher::publish('assignment.deleted', $payload, ['admin', 'driver']);

        return response()->json([
            'message' => 'Assignment berhasil dihapus'
        ]);
    }
}