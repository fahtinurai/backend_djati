<?php

namespace App\Services;

use Kreait\Laravel\Firebase\Facades\Firebase;

class FirestoreService
{
    /**
     * Simpan notifikasi ke Firestore untuk 1 user:
     * users/{userId}/notifications/{autoId}
     *
     * Payload dari Controller kamu:
     * [
     *   'title' => string,
     *   'body'  => string,
     *   'type'  => string,
     *   'role'  => string,
     *   'data'  => array (opsional)
     * ]
     */
    public function pushUserNotification(int $userId, array $payload): void
    {
        if ($userId <= 0) return;

        // default
        $title = isset($payload['title']) ? (string) $payload['title'] : '';
        $body  = isset($payload['body'])  ? (string) $payload['body']  : '';
        $type  = isset($payload['type'])  ? (string) $payload['type']  : '';
        $role  = isset($payload['role'])  ? (string) $payload['role']  : '';

        $data = [];
        if (isset($payload['data']) && is_array($payload['data'])) {
            $data = $payload['data'];
        }

        // Normalisasi data supaya aman di Flutter:
        // - cast scalar ke string/int/bool sesuai kebutuhan
        // - nested array tetap array
        $data = $this->normalizeData($data);

        $doc = [
            'title'      => $title,
            'body'       => $body,
            'type'       => $type,
            'role'       => $role,
            'data'       => $data,
            'unread'     => true,
            'created_at' => now()->toISOString(), // ISO string
        ];

        $db = Firebase::firestore()->database();

        $db->collection('users')
            ->document((string) $userId)
            ->collection('notifications')
            ->add($doc);
    }

    /**
     * Mark-as-read 1 notif:
     * users/{userId}/notifications/{notifId}
     */
    public function markAsRead(int $userId, string $notifId): void
    {
        if ($userId <= 0) return;
        $notifId = trim($notifId);
        if ($notifId === '') return;

        $db = Firebase::firestore()->database();

        $db->collection('users')
            ->document((string) $userId)
            ->collection('notifications')
            ->document($notifId)
            ->update([
                ['path' => 'unread', 'value' => false],
                ['path' => 'read_at', 'value' => now()->toISOString()],
            ]);
    }

    /**
     * Mark-as-read semua notif user (opsional kalau kamu butuh)
     * Ini bisa mahal kalau notif banyak, jadi default tidak dipakai.
     */
    public function markAllAsRead(int $userId, int $limit = 200): void
    {
        if ($userId <= 0) return;

        $db = Firebase::firestore()->database();

        $docs = $db->collection('users')
            ->document((string) $userId)
            ->collection('notifications')
            ->where('unread', '==', true)
            ->limit($limit)
            ->documents();

        foreach ($docs as $doc) {
            if (!$doc->exists()) continue;

            $db->collection('users')
                ->document((string) $userId)
                ->collection('notifications')
                ->document($doc->id())
                ->update([
                    ['path' => 'unread', 'value' => false],
                    ['path' => 'read_at', 'value' => now()->toISOString()],
                ]);
        }
    }

    /**
     * Helper normalisasi data (biar gak ada object aneh yang bikin gagal encode).
     */
    private function normalizeData(array $data): array
    {
        $out = [];

        foreach ($data as $k => $v) {
            $key = is_string($k) ? $k : (string) $k;

            if (is_null($v)) {
                $out[$key] = null;
            } elseif (is_bool($v)) {
                $out[$key] = $v;
            } elseif (is_int($v)) {
                $out[$key] = $v;
            } elseif (is_float($v)) {
                $out[$key] = $v;
            } elseif (is_string($v)) {
                $out[$key] = $v;
            } elseif (is_array($v)) {
                $out[$key] = $this->normalizeData($v);
            } else {
                // fallback: jadikan string
                $out[$key] = (string) $v;
            }
        }

        return $out;
    }
}
