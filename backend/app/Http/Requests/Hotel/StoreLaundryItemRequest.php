<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class StoreLaundryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_laundry.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', 'unique:laundry_items,name'],
            'price' => ['required', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
