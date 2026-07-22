<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_rooms.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'max:20', Rule::unique('rooms', 'number')],
            'room_type_id' => ['required', 'integer', 'exists:room_types,id'],
            'floor' => ['nullable', 'string', 'max:50'],
            'view' => ['nullable', 'string', 'max:50'],
            'amenities' => ['array'],
            'amenities.*' => ['string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
