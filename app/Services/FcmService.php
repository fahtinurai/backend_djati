<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Exception\FirebaseException;

use App\Models\UserFcmToken;
use App\Models\User;

class FcmService
{
    protected $messaging;

    public function __construct()
    {
        // Contoh isi env:
        // FIREBASE_CREDENTIALS=storage/app/firebase/tubesmobile-xxx-firebase-adminsdk-xxx.json
        // atau absolute path

        $relative = env('FIREBASE_CREDENTIALS');

        if (!$relative) {
            throw new \RuntimeException('FIREBASE_CREDENTIALS belum di-set di .env');
        }

        // Kalau absolute path: /var/... atau C:\...
        // Kalau relative: storage/app/... -> jadikan absolute dengan base_path()
        $isWindowsAbs = preg_match('/^[A-Z]:\\\\/i', $relative) === 1;
        $isUnixAbs = str_starts_with($relative, DIRECTORY_SEPARATOR);

        $credentialsPath = ($isUnixAbs || $isWindowsAbs)
            ? $relative
            : base_path($relative);

        if (!file_exists($credentialsPath)) {
            throw new \RuntimeException("Firebase credentials tidak ditemukan: {$credentialsPath}");
        }

        $this->messaging = (new Factory())
            ->withServiceAccount($credentialsPath)
            ->createMessaging();
    }

    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): void
    {
        $tokens = array_values(array_filter(array_unique(array_map('trim', $tokens))));
        if (empty($tokens)) return;

        // FCM data HARUS string
        $dataStr = [];
        foreach ($data as $k => $v) {
            $key = (string) $k;

            if (is_null($v)) {
                $dataStr[$key] = '';
                continue;
            }

            // Scalar -> cast string biasa (lebih aman daripada json_encode untuk angka/bool)
            if (is_string($v) || is_numeric($v) || is_bool($v)) {
                $dataStr[$key] = (string) $v;
                continue;
            }

            // Array / object -> JSON
            $dataStr[$key] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData($dataStr);

        try {
            /** @var MulticastSendReport $report */
            $report = $this->messaging->sendMulticast($message, $tokens);

            // ✅ Bersihkan token invalid dari DB (opsional tapi sangat membantu)
            if ($report->hasFailures()) {
                foreach ($report->failures()->getItems() as $failure) {
                    $failedToken = $failure->target()->value();
                    $errorMsg = $failure->error()->getMessage();

                    // Hapus token yang sudah tidak valid / unregistered
                    // (biar next send tidak selalu gagal)
                    if (stripos($errorMsg, 'NotRegistered') !== false ||
                        stripos($errorMsg, 'registration token is not a valid FCM registration token') !== false ||
                        stripos($errorMsg, 'Unregistered') !== false) {
                        UserFcmToken::where('token', $failedToken)->delete();
                    }
                }
            }
        } catch (MessagingException|FirebaseException $e) {
            // Supaya kalau gagal, kamu bisa lihat jelas di log Laravel
            logger()->error('FCM sendMulticast failed', [
                'message' => $e->getMessage(),
                'tokens_count' => count($tokens),
                'data' => $dataStr,
            ]);
        } catch (\Throwable $e) {
            logger()->error('FCM unexpected error', [
                'message' => $e->getMessage(),
                'tokens_count' => count($tokens),
            ]);
        }
    }

    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        $tokens = UserFcmToken::where('user_id', $user->id)->pluck('token')->toArray();
        $this->sendToTokens($tokens, $title, $body, $data);
    }

    public function sendToRole(string $role, string $title, string $body, array $data = []): void
    {
        // ⚠️ Pastikan role konsisten (misal: 'teknisi' bukan 'technician')
        // Kalau di DB role-nya 'teknisi', kirim role 'teknisi'.

        $tokens = UserFcmToken::whereHas('user', function ($q) use ($role) {
                $q->where('role', $role);
            })
            ->pluck('token')
            ->toArray();

        $this->sendToTokens($tokens, $title, $body, $data);
    }
}
