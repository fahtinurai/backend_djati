<?php

namespace App\Services;

use Kreait\Laravel\Firebase\Facades\Firebase;

class FirestoreInboxService
{
    /**
     * Simpan notif ke:
     * users/{userId}/notifications/{autoId}
     *
     * Field minimal yang dipakai Flutter:
     * - unread: bool
     * - created_at: ISO string
     * - title, body, type, role, report_id, booking_id, status (opsional)
     */
    public function pushToUser(string|int $userId, array $payload): void
    {
        $uid = trim((string) $userId);
        if ($uid === '') return;

        $nowIso = now()->toISOString();

        $data = array_merge([
            'unread' => true,
            'created_at' => $nowIso,
        ], $payload);

        // Amanin beberapa field jadi string (biar konsisten di Flutter)
        foreach ([
            'type','role','title','body','status','report_id','booking_id'
        ] as $k) {
            if (array_key_exists($k, $data) && $data[$k] !== null) {
                $data[$k] = is_string($data[$k]) ? $data[$k] : (string) $data[$k];
            }
        }

        $db = Firebase::firestore()->database();

        $db->collection('users')
            ->document($uid)
            ->collection('notifications')
            ->add($data);
    }
}
