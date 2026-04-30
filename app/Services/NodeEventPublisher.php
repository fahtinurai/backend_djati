<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class NodeEventPublisher
{
    public static function publish(string $event, array $data = [], array $channels = ['admin']): void
    {
        try {
            $base = rtrim((string) config('services.node_events.url'), '/');
            $key  = (string) config('services.node_events.key');

            if ($base === '' || $key === '') return;

            Http::timeout(2)
                ->withHeaders(['x-service-key' => $key])
                ->post($base . '/events/publish', [
                    'event' => $event,
                    'channels' => $channels,
                    'data' => $data,
                ]);
        } catch (\Throwable $e) {
            // Fail-safe: jangan ganggu proses utama Laravel kalau Node down
        }
    }
}
