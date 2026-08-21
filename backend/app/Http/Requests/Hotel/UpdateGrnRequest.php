<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGrnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_grn.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reference' => ['sometimes', 'nullable', 'string', 'max:150'],
            'received_at' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines' => ['sometimes', 'array', 'min:1'],
            'lines.*.ingredient_id' => ['required_with:lines', 'integer', 'exists:ingredients,id'],
            'lines.*.qty' => ['required_with:lines', 'numeric', 'min:0.001'],
            'lines.*.unit_cost' => ['required_with:lines', 'integer', 'min:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:100'],
            'lines.*.manufactured_at' => ['nullable', 'date'],
            'lines.*.expiry_date' => ['nullable', 'date'],
        ];
    }
}
