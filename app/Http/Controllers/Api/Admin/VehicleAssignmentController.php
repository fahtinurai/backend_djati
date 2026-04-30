<?php
// Nur Ahmadi Aditya Nanda
namespace App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;
use App\Services\NodeEventPublisher;

class VehicleAssignmentController extends Controller
{
    /**
     * Daftar semua assignment
     */
    public function index()
    {
        $assignments = VehicleAssignment::with(['vehicle', 'driver'])
            ->orderBy('assigned_at', 'desc')
            ->get();

        return response()->json($assignments);
    }

    /**
     * Assign kendaraan ke driver
     */
    public function store(Request $request)
    {
        $request->validate([
            'vehicle_id' => 'required|exists:vehicles,id',
            'driver_id'  => 'required|exists:users,id',
        ]);

        $driver = User::findOrFail($request->driver_id);

        if ($driver->role !== 'driver') {
            return response()->json(['message' => 'User bukan driver'], 400);
        }

        // Cek apakah kendaraan sudah di-assign
        $existingAssignment = VehicleAssignment::where('vehicle_id', $request->vehicle_id)->first();
        if ($existingAssignment) {
            return response()->json(['message' => 'Kendaraan sudah di-assign ke driver lain'], 400);
        }

        $assignment = VehicleAssignment::create([
            'vehicle_id'  => $request->vehicle_id,
            'driver_id'   => $request->driver_id,
            'assigned_at' => now(),
        ]);

        $assignment->load(['vehicle', 'driver']);

        // publish event realtime ke web admin
        NodeEventPublisher::publish('assignment.created', [
            'assignment_id' => $assignment->id,
            'vehicle_id'    => $assignment->vehicle_id,
            'driver_id'     => $assignment->driver_id,
            'assigned_at'   => $assignment->assigned_at,
        ], ['admin']);

        return response()->json($assignment, 201);
    }

    /**
     * Hapus assignment (unassign kendaraan dari driver)
     */
    public function destroy(VehicleAssignment $vehicleAssignment)
    {
        // simpan data sebelum delete (buat payload event)
        $payload = [
            'assignment_id' => $vehicleAssignment->id,
            'vehicle_id'    => $vehicleAssignment->vehicle_id,
            'driver_id'     => $vehicleAssignment->driver_id,
        ];

        $vehicleAssignment->delete();

        // publish event realtime ke web admin
        NodeEventPublisher::publish('assignment.deleted', $payload, ['admin']);

        return response()->json(['message' => 'Assignment berhasil dihapus']);
    }
}
