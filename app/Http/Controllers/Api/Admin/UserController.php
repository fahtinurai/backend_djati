<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VehicleAssignment;
use App\Models\DamageReport;
use App\Models\ServiceBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Services\NodeEventPublisher;

class UserController extends Controller
{
    /**
     * GET /api/admin/users
     */
    public function index()
    {
        $users = User::orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'data' => $users,
        ]);
    }

    /**
     * POST /api/admin/users
     */
    public function store(Request $request)
    {
        $this->normalizeInput($request);

        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'username'  => 'required|string|max:255|unique:users,username',
            'password'  => 'required|string|min:6',
            'role'      => 'required|in:admin,driver,teknisi',
        ]);

        $user = User::create([
            'name'      => $validated['name'],
            'username'  => $validated['username'],
            'password'  => Hash::make($validated['password']),
            'role'      => $validated['role'],
            'is_active' => true,
        ]);

        $this->publishRealtimeEvent('user.created', [
            'id'         => $user->id,
            'name'       => $user->name,
            'username'   => $user->username,
            'role'       => $user->role,
            'is_active'  => $user->is_active,
            'created_at' => $user->created_at,
        ]);

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'data'    => $user,
        ], 201);
    }

    /**
     * PUT/PATCH /api/admin/users/{user}
     */
    public function update(Request $request, User $user)
    {
        $this->normalizeInput($request);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',

            'username' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($user->id),
            ],

            'password'  => 'sometimes|nullable|string|min:6',
            'role'      => 'sometimes|in:admin,driver,teknisi',
            'is_active' => 'sometimes|boolean',
        ]);

        /*
        |--------------------------------------------------------------------------
        | BLOKIR PERUBAHAN ROLE JIKA MASIH ADA AKTIVITAS BERJALAN
        |--------------------------------------------------------------------------
        | Contoh:
        | - Driver masih punya kendaraan / laporan / booking aktif
        | - Teknisi masih punya jadwal maintenance aktif
        */
        if (
            isset($validated['role']) &&
            $validated['role'] !== $user->role &&
            $this->userHasRunningActivity($user)
        ) {
            return response()->json([
                'message' => 'Role user tidak dapat diubah karena masih memiliki aktivitas yang sedang berjalan.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | OPSIONAL TAPI AMAN:
        | BLOKIR NONAKTIFKAN USER JIKA MASIH ADA AKTIVITAS BERJALAN
        |--------------------------------------------------------------------------
        */
        if (
            array_key_exists('is_active', $validated) &&
            (bool) $validated['is_active'] === false &&
            $this->userHasRunningActivity($user)
        ) {
            return response()->json([
                'message' => $this->getRunningActivityMessage($user),
            ], 422);
        }

        $data = [];

        foreach (['name', 'username', 'role', 'is_active'] as $field) {
            if (array_key_exists($field, $validated)) {
                $data[$field] = $validated[$field];
            }
        }

        if ($request->filled('password')) {
            $data['password'] = Hash::make($validated['password']);
        }

        $user->update($data);
        $user->refresh();

        $this->publishRealtimeEvent('user.updated', [
            'id'         => $user->id,
            'name'       => $user->name,
            'username'   => $user->username,
            'role'       => $user->role,
            'is_active'  => $user->is_active,
            'updated_at' => $user->updated_at,
        ]);

        return response()->json([
            'message' => 'User berhasil diperbarui.',
            'data'    => $user,
        ]);
    }

    /**
     * DELETE /api/admin/users/{user}
     */
    public function destroy(Request $request, User $user)
    {
        /*
        |--------------------------------------------------------------------------
        | BLOKIR ADMIN MENGHAPUS AKUN SENDIRI
        |--------------------------------------------------------------------------
        */
        if ((int) $request->user()->id === (int) $user->id) {
            return response()->json([
                'message' => 'Akun yang sedang digunakan tidak dapat dihapus.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | BLOKIR ADMIN TERAKHIR
        |--------------------------------------------------------------------------
        */
        if ($user->role === 'admin' && (bool) $user->is_active === true) {
            $activeAdminCount = User::where('role', 'admin')
                ->where('is_active', true)
                ->count();

            if ($activeAdminCount <= 1) {
                return response()->json([
                    'message' => 'Admin terakhir tidak dapat dihapus.',
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | BLOKIR HAPUS DRIVER / TEKNISI JIKA MASIH ADA PROGRESS
        |--------------------------------------------------------------------------
        */
        if ($this->userHasRunningActivity($user)) {
            return response()->json([
                'message' => $this->getRunningActivityMessage($user),
            ], 422);
        }

        $payload = [
            'id'        => $user->id,
            'name'      => $user->name,
            'username'  => $user->username,
            'role'      => $user->role,
            'is_active' => $user->is_active,
        ];

        $user->delete();

        $this->publishRealtimeEvent('user.deleted', $payload);

        return response()->json([
            'message' => 'User berhasil dihapus.',
        ]);
    }

    /**
     * Normalisasi input sebelum validasi.
     */
    private function normalizeInput(Request $request): void
    {
        $normalized = [];

        if ($request->has('name')) {
            $normalized['name'] = trim((string) $request->name);
        }

        if ($request->has('username')) {
            $normalized['username'] = strtolower(trim((string) $request->username));
        }

        if ($request->has('role')) {
            $normalized['role'] = strtolower(trim((string) $request->role));
        }

        $request->merge($normalized);
    }

    /**
     * Cek apakah user masih memiliki aktivitas berjalan.
     */
    private function userHasRunningActivity(User $user): bool
    {
        if ($user->role === 'driver') {
            return $this->driverHasRunningActivity($user);
        }

        if ($user->role === 'teknisi') {
            return $this->technicianHasRunningActivity($user);
        }

        return false;
    }

    /**
     * Cek aktivitas berjalan milik driver.
     */
    private function driverHasRunningActivity(User $user): bool
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Driver masih punya kendaraan aktif
        |--------------------------------------------------------------------------
        | Karena sistem kamu memakai VehicleAssignment sebagai kendaraan aktif,
        | maka driver yang masih punya assignment tidak boleh dihapus.
        */
        $hasVehicleAssignment = VehicleAssignment::where('driver_id', $user->id)
            ->exists();

        if ($hasVehicleAssignment) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Driver masih punya booking service berjalan
        |--------------------------------------------------------------------------
        */
        $hasRunningBooking = ServiceBooking::where('driver_id', $user->id)
            ->whereIn('status', $this->runningBookingStatuses())
            ->exists();

        if ($hasRunningBooking) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Driver masih punya laporan kerusakan yang belum selesai
        |--------------------------------------------------------------------------
        | Kalau belum ada response teknisi, berarti laporan masih menunggu.
        | Kalau sudah ada response, status terakhir harus status selesai/ditutup.
        */
        $closedStatuses = $this->closedDamageResponseStatuses();

        $hasRunningDamageReport = DamageReport::where('driver_id', $user->id)
            ->where(function ($query) use ($closedStatuses) {
                $query->whereDoesntHave('latestTechnicianResponse')
                    ->orWhereHas('latestTechnicianResponse', function ($response) use ($closedStatuses) {
                        $response->whereNotIn('status', $closedStatuses);
                    });
            })
            ->exists();

        if ($hasRunningDamageReport) {
            return true;
        }

        return false;
    }

    /**
     * Cek aktivitas berjalan milik teknisi.
     */
    private function technicianHasRunningActivity(User $user): bool
    {
        /*
        |--------------------------------------------------------------------------
        | Teknisi masih punya jadwal maintenance aktif
        |--------------------------------------------------------------------------
        */
        return ServiceBooking::where('technician_id', $user->id)
            ->whereIn('status', $this->technicianRunningStatuses())
            ->exists();
    }

    /**
     * Pesan error sesuai role.
     */
    private function getRunningActivityMessage(User $user): string
    {
        if ($user->role === 'driver') {
            return 'Driver tidak dapat dihapus karena masih memiliki kendaraan, laporan kerusakan, atau booking service yang sedang berjalan.';
        }

        if ($user->role === 'teknisi') {
            return 'Teknisi tidak dapat dihapus karena masih memiliki jadwal maintenance yang sedang berjalan.';
        }

        return 'User tidak dapat dihapus karena masih memiliki aktivitas yang sedang berjalan.';
    }

    /**
     * Status booking yang dianggap masih berjalan untuk driver.
     */
    private function runningBookingStatuses(): array
    {
        return [
            'requested',
            'approved',
            'scheduled',
            'rescheduled',
            'in_progress',
            'waiting_parts',
        ];
    }

    /**
     * Status booking yang dianggap masih berjalan untuk teknisi.
     */
    private function technicianRunningStatuses(): array
    {
        return [
            'approved',
            'scheduled',
            'rescheduled',
            'in_progress',
            'waiting_parts',
        ];
    }

    /**
     * Status response teknisi yang dianggap selesai / tertutup.
     */
    private function closedDamageResponseStatuses(): array
    {
        return [
            'completed',
            'finished',
            'selesai',
            'rejected',
            'canceled',
            'cancelled',
        ];
    }

    /**
     * Publish realtime event ke Node.
     * Jika realtime gagal, proses utama tetap berhasil.
     */
    private function publishRealtimeEvent(string $event, array $data): void
    {
        try {
            NodeEventPublisher::publish($event, $data, ['admin']);
        } catch (\Throwable $e) {
            logger()->warning('Realtime user event failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}