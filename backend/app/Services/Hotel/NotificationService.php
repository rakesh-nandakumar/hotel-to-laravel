<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Notification;
use App\Models\Lookup;
use App\Services\Hotel\Notifications\ConsoleStubProvider;
use App\Services\Hotel\Notifications\HttpSmsProvider;
use App\Services\Hotel\Notifications\NotificationProvider;
use App\Services\Hotel\Notifications\WhatsAppCloudProvider;
use App\Services\Settings;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\NotificationChannel;
use App\Support\Lookups\NotificationStatus;

/**
 * Notification layer — automated WhatsApp + SMS with pluggable providers,
 * resolved per-send from Settings (category "integrations", System-Admin-only).
 * Email stays a console stub — Node never implemented real SMTP either
 * (marked TODO(Phase2) in its own code); a disabled/unconfigured channel
 * always falls back to simulated so business flows keep working end to end.
 * Ported from the Node app's lib/notify.ts.
 */
class NotificationService
{
    /**
     * @param  array{type: string, channel: string, to: string, subject: string, body: string, ref_type?: string|null, ref_id?: int|null}  $opts
     */
    public function send(array $opts): Notification
    {
        $notification = Notification::create([
            'type' => $opts['type'],
            'notification_channel_id' => Lookup::id(LookupType::NOTIFICATION_CHANNEL, $opts['channel']),
            'to' => $opts['to'],
            'subject' => $opts['subject'],
            'body' => $opts['body'],
            'notification_status_id' => Lookup::id(LookupType::NOTIFICATION_STATUS, NotificationStatus::QUEUED),
            'ref_type' => $opts['ref_type'] ?? null,
            'ref_id' => $opts['ref_id'] ?? null,
        ]);

        $provider = $this->providerFor($opts['channel']);

        try {
            $provider->send($opts['to'], $opts['subject'], $opts['body']);
            $notification->update([
                'notification_status_id' => Lookup::id(LookupType::NOTIFICATION_STATUS, NotificationStatus::SENT),
                'sent_at' => now(),
                'error' => $provider->isSimulated() ? 'SIMULATED — channel not configured/enabled (Integrations)' : null,
            ]);
        } catch (\Throwable $e) {
            $notification->update([
                'notification_status_id' => Lookup::id(LookupType::NOTIFICATION_STATUS, NotificationStatus::FAILED),
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }

        return $notification->fresh(['channel', 'status']);
    }

    /**
     * Send to a guest on every enabled channel they have contact details for.
     *
     * @param  array{email?: string|null, phone?: string|null}  $contact
     * @param  array{type: string, subject: string, body: string, ref_type?: string|null, ref_id?: int|null}  $message
     */
    public function notifyGuest(array $contact, array $message): void
    {
        $channels = Settings::json('notifications.channels', [NotificationChannel::EMAIL, NotificationChannel::WHATSAPP, NotificationChannel::SMS]);

        if (! empty($contact['email']) && in_array(NotificationChannel::EMAIL, $channels, true)) {
            $this->send(array_merge($message, ['channel' => NotificationChannel::EMAIL, 'to' => $contact['email']]));
        }

        if (! empty($contact['phone'])) {
            if (in_array(NotificationChannel::WHATSAPP, $channels, true)) {
                $this->send(array_merge($message, ['channel' => NotificationChannel::WHATSAPP, 'to' => $contact['phone']]));
            }
            if (in_array(NotificationChannel::SMS, $channels, true)) {
                $this->send(array_merge($message, ['channel' => NotificationChannel::SMS, 'to' => $contact['phone']]));
            }
        }
    }

    private function providerFor(string $channel): NotificationProvider
    {
        if ($channel === NotificationChannel::WHATSAPP) {
            $enabled = Settings::get('integrations.whatsapp_enabled', false);
            $url = Settings::str('integrations.whatsapp_api_url');
            $token = Settings::str('integrations.whatsapp_api_token');
            if ($enabled && $url && $token) {
                return new WhatsAppCloudProvider($url, $token);
            }

            return new ConsoleStubProvider('whatsapp');
        }

        if ($channel === NotificationChannel::SMS) {
            $enabled = Settings::get('integrations.sms_enabled', false);
            $url = Settings::str('integrations.sms_api_url');
            $key = Settings::str('integrations.sms_api_key');
            $senderId = Settings::str('integrations.sms_sender_id', 'MountView');
            if ($enabled && $url && $key) {
                return new HttpSmsProvider($url, $key, $senderId);
            }

            return new ConsoleStubProvider('sms');
        }

        // TODO(Phase 3+): real SMTP provider via Laravel Mail — Node never had one either.
        return new ConsoleStubProvider('email');
    }
}
