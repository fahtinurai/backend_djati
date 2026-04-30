<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

// 🔴 TAMBAHAN REALTIME
use App\Services\NodeEventPublisher;

class UserController extends Controller
{
    /**
     * Daftar semua user (driver, teknisi, admin)
     */
    public function index()
    {
        $users = User::orderBy('created_at', 'desc')->get();
        return response()->json($users);
    }

    /**
     * Buat user baru (driver/teknisi/admin)
     */
    public function store(Request $request)
    {
        $request->validate([
            'username'  => 'required|unique:users',
            'password'  => 'required|min:6',
            'role'      => 'required|in:admin,driver,teknisi',
        ]);

        $user = User::create([
            'username'  => $request->username,
            'password'  => Hash::make($request->password),
            'role'      => $request->role,
            'is_active' => true,
        ]);

        // 🔴 REALTIME: user created
        NodeEventPublisher::publish(
            'user.created',
            [
                'id'        => $user->id,
                'username'  => $user->username,
                'role'      => $user->role,
                'is_active' => $user->is_active,
                'created_at'=> $user->created_at,
            ],
            ['admin']
        );

        return response()->json($user, 201);
    }

    /**
     * Update user
     */
    public function update(Request $request, User $user)
    {
        $request->validate([
            'username'  => 'sometimes|unique:users,username,' . $user->id,
            'password'  => 'sometimes|min:6',
            'role'      => 'sometimes|in:admin,driver,teknisi',
            'is_active' => 'sometimes|boolean',
        ]);

        $data = $request->only(['username', 'role', 'is_active']);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        // 🔴 REALTIME: user updated (aktif/nonaktif, role, username)
        NodeEventPublisher::publish(
            'user.updated',
            [
                'id'        => $user->id,
                'username'  => $user->username,
                'role'      => $user->role,
                'is_active' => $user->is_active,
                'updated_at'=> $user->updated_at,
            ],
            ['admin']
        );

        return response()->json($user);
    }

    /**
     * Hapus user
     */
    public function destroy(User $user)
    {
        $payload = [
            'id'       => $user->id,
            'username' => $user->username,
            'role'     => $user->role,
        ];

        $user->delete();

        // 🔴 REALTIME: user deleted
        NodeEventPublisher::publish(
            'user.deleted',
            $payload,
            ['admin']
        );

        return response()->json(['message' => 'User berhasil dihapus']);
    }
}
