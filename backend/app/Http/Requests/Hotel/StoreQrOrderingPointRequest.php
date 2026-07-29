<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQrOrderingPointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_qr_ordering.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'room_id' => [
                'required_without:dining_table_id', 'prohibits:dining_table_id', 'nullable', 'integer',
                'exists:rooms,id', Rule::unique('qr_ordering_points', 'room_id'),
            ],
            'dining_table_id' => [
                'required_without:room_id', 'prohibits:room_id', 'nullable', 'integer',
                'exists:dining_tables,id', Rule::unique('qr_ordering_points', 'dining_table_id'),
            ],
        ];
    }
}
