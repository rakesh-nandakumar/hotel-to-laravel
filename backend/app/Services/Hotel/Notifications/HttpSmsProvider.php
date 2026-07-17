<?php

namespace App\Services\Hotel\Notifications;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Generic HTTP SMS gateway (notify.lk / Dialog eSMS / similar). */
class HttpSmsProvider implements NotificationProvider
{
    public function __construct(private readonly string $url, private readonly string $key, private readonly string $senderId) {}

    public function send(string $to, string $subject, string $body): void
    {
        $response = Http::withToken($this->key)->post($this->url, [
            'to' => $this->normalizePhone($to),
            'message' => $body,
            'sender_id' => $this->senderId,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("SMS gateway {$response->status()}: ".mb_substr($response->body(), 0, 300));
        }
    }

    public function isSimulated(): bool
    {
        return false;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^\d+]/', '', $phone) ?? $phone;
    }
}
