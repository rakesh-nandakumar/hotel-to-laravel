<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_reservations.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'adults' => ['sometimes', 'integer', 'min:1'],
            'children' => ['sometimes', 'integer', 'min:0'],
            'package_id' => ['sometimes', 'nullable', 'integer', 'exists:packages,id'],
        ];
    }
}
