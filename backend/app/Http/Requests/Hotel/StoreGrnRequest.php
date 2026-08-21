<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class StoreGrnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_grn.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reference' => ['nullable', 'string', 'max:150'],
            'received_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'lines.*.qty' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit_cost' => ['required', 'integer', 'min:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:100'],
            'lines.*.manufactured_at' => ['nullable', 'date'],
            'lines.*.expiry_date' => ['nullable', 'date'],
        ];
    }
}
