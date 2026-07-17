<?php

namespace App\Services\Hotel\Notifications;

use Illuminate\Support\Facades\Log;

/**
 * Fallback for any disabled/unconfigured channel (and EMAIL, always — Node
 * never implemented real SMTP either, marked TODO(Phase2) in its own code).
 * Logs instead of sending so business flows keep working end to end.
 */
class ConsoleStubProvider implements NotificationProvider
{
    public function __construct(private readonly string $channel) {}

    public function send(string $to, string $subject, string $body): void
    {
        Log::info("[notify:{$this->channel}:SIMULATED] → {$to} | {$subject}\n".mb_substr($body, 0, 200));
    }

    public function isSimulated(): bool
    {
        return true;
    }
}
