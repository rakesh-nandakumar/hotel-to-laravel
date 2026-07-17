<?php

namespace App\Http\Requests\Hotel;

use App\Support\Lookups\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendTestNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_notifications.test') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', Rule::in([
                NotificationChannel::WHATSAPP,
                NotificationChannel::SMS,
                NotificationChannel::EMAIL,
            ])],
            'to' => ['required', 'string', 'min:3'],
        ];
    }
}
