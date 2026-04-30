<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\UserFcmToken;

class FcmTokenController extends Controller
{
    /**
     * POST /api/mobile/fcm-token
     */
    public function store(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'platform' => 'nullable|string',
        ]);

        $userId = auth()->id();

        if (!$userId) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 401);
        }

        // token UNIQUE → pakai token sebagai kunci
        UserFcmToken::updateOrCreate(
            ['token' => $request->token],
            [
                'user_id' => $userId,
                'platform' => $request->platform,
            ]
        );

        return response()->json([
            'message' => 'FCM token saved successfully'
        ], 200);
    }

    /**
     * POST /api/mobile/fcm-token/delete
     */
    public function destroy(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $userId = auth()->id();

        if (!$userId) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 401);
        }

        UserFcmToken::where('user_id', $userId)
            ->where('token', $request->token)
            ->delete();

        return response()->json([
            'message' => 'FCM token deleted successfully'
        ], 200);
    }
}
