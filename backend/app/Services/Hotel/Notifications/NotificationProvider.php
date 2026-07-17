<?php

namespace App\Services\Hotel\Notifications;

interface NotificationProvider
{
    public function send(string $to, string $subject, string $body): void;

    public function isSimulated(): bool;
}
