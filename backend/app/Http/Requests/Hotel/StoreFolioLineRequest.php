<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFolioLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_folios.add_line') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source' => [
                'required', 'string', Rule::in(['minibar', 'laundry', 'damage', 'adjustment', 'venue', 'surcharge']),
            ],
            'description' => ['required', 'string', 'max:255'],
            'qty' => ['nullable', 'numeric', 'min:0.01'],
            'unit_price' => ['required', 'integer'],
        ];
    }
}
