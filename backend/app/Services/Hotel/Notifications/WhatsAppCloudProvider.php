<?php

namespace App\Services\Hotel\Notifications;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Meta WhatsApp Cloud API. */
class WhatsAppCloudProvider implements NotificationProvider
{
    public function __construct(private readonly string $url, private readonly string $token) {}

    public function send(string $to, string $subject, string $body): void
    {
        $response = Http::withToken($this->token)->post($this->url, [
            'messaging_product' => 'whatsapp',
            'to' => $this->normalizePhone($to),
            'type' => 'text',
            'text' => ['body' => "*{$subject}*\n\n{$body}"],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("WhatsApp API {$response->status()}: ".mb_substr($response->body(), 0, 300));
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
