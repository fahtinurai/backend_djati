<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Services\NodeEventPublisher;

class UserController extends Controller
{
    public function index()
    {
        $users = User::orderBy('created_at', 'desc')->get();

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'      => 'required|string|max:255',
            'username'  => 'required|string|max:255|unique:users,username',
            'password'  => 'required|string|min:6',
            'role'      => 'required|in:admin,driver,teknisi',
        ]);

        $user = User::create([
            'name'      => $request->name,
            'username'  => $request->username,
            'password'  => Hash::make($request->password),
            'role'      => $request->role,
            'is_active' => true,
        ]);

        NodeEventPublisher::publish(
            'user.created',
            [
                'id'         => $user->id,
                'name'       => $user->name,
                'username'   => $user->username,
                'role'       => $user->role,
                'is_active'  => $user->is_active,
                'created_at' => $user->created_at,
            ],
            ['admin']
        );

        return response()->json($user, 201);
    }

    public function update(Request $request, User $user)
    {
        $request->validate([
            'name'      => 'sometimes|string|max:255',
            'username'  => 'sometimes|string|max:255|unique:users,username,' . $user->id,
            'password'  => 'sometimes|nullable|string|min:6',
            'role'      => 'sometimes|in:admin,driver,teknisi',
            'is_active' => 'sometimes|boolean',
        ]);

        $data = $request->only([
            'name',
            'username',
            'role',
            'is_active',
        ]);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        NodeEventPublisher::publish(
            'user.updated',
            [
                'id'         => $user->id,
                'name'       => $user->name,
                'username'   => $user->username,
                'role'       => $user->role,
                'is_active'  => $user->is_active,
                'updated_at' => $user->updated_at,
            ],
            ['admin']
        );

        return response()->json($user);
    }

    public function destroy(User $user)
    {
        $payload = [
            'id'       => $user->id,
            'name'     => $user->name,
            'username' => $user->username,
            'role'     => $user->role,
        ];

        $user->delete();

        NodeEventPublisher::publish(
            'user.deleted',
            $payload,
            ['admin']
        );

        return response()->json(['message' => 'User berhasil dihapus']);
    }
}