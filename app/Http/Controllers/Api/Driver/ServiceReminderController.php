<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Illuminate\Http\Request;

class ServiceReminderController extends Controller
{
    /**
     * Update / set service reminder untuk kendaraan driver
     *
     * Route:
     * PUT /driver/vehicles/{vehicle}/service-reminder
     */
    public function update(Request $request, Vehicle $vehicle)
    {
        $driver = $request->user();

        // ===============================
        // 🔐 AUTHORIZATION (sesuai pola VehicleAssignment)
        // ===============================
        // pastikan kendaraan ini memang di-assign ke driver yang login
        $assigned = VehicleAssignment::where('vehicle_id', $vehicle->id)
            ->where('driver_id', $driver->id)
            ->exists();

        if (!$assigned) {
            return response()->json([
                'message' => 'Forbidden'
            ], 403);
        }

        // ===============================
        // ✅ VALIDATION
        // ===============================
        $validated = $request->validate([
            'next_service_at' => ['nullable', 'date'],
            'reminder_enabled' => ['required', 'boolean'],
            'reminder_days_before' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        // kalau reminder aktif → next_service_at wajib
        if ($validated['reminder_enabled'] && empty($validated['next_service_at'])) {
            return response()->json([
                'message' => 'next_service_at wajib diisi jika reminder aktif.'
            ], 422);
        }

        // ===============================
        // 💾 UPDATE DATA
        // ===============================
        $vehicle->update([
            'next_service_at' => $validated['next_service_at'],
            'reminder_enabled' => $validated['reminder_enabled'],
            'reminder_days_before' => $validated['reminder_days_before'],
        ]);

        return response()->json([
            'message' => 'Service reminder berhasil diperbarui.',
            'data' => $vehicle->fresh(),
        ]);
    }
}
