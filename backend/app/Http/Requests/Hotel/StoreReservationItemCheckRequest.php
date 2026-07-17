<?php

namespace App\Http\Requests\Hotel;

use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReservationItemCheckRequest extends FormRequest
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
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'kind' => ['required', 'string', Rule::exists('lookups', 'code')->where('type', LookupType::CHECK_KIND)],
            'items' => ['required', 'array'],
            'items.*.item' => ['required', 'string', 'max:150'],
            'items.*.ok' => ['required', 'boolean'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
