<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_room_types.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('room_types', 'name')],
            'max_occupancy' => ['required', 'integer', 'min:1'],
            'bed_config' => ['nullable', 'string', 'max:255'],
            'amenities' => ['array'],
            'amenities.*' => ['string', 'max:120'],
            'weekday_rate' => ['required', 'integer', 'min:0'],
            'weekend_rate' => ['required', 'integer', 'min:0'],
            'item_checklist' => ['array'],
            'item_checklist.*' => ['string', 'max:255'],
            'cleaning_checklist' => ['array'],
            'cleaning_checklist.*' => ['string', 'max:255'],
        ];
    }
}
