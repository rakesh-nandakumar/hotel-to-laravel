<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class AdjustGuestLoyaltyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_guests.loyalty_adjust') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'points' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
