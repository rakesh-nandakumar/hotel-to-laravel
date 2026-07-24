<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDiningTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_dining_tables.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'table_no' => ['sometimes', 'string', 'max:20', Rule::unique('dining_tables', 'table_no')->ignore($this->route('diningTable'))],
            'dining_area_id' => ['sometimes', 'nullable', 'integer', 'exists:dining_areas,id'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
