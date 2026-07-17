<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_ingredients.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150', Rule::unique('ingredients', 'name')->ignore($this->route('ingredient'))],
            'unit' => ['sometimes', 'string', 'max:20'],
            'stock_qty' => ['sometimes', 'numeric', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
