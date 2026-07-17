<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class StoreIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_ingredients.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', 'unique:ingredients,name'],
            'unit' => ['required', 'string', 'max:20'],
            'stock_qty' => ['required', 'numeric', 'min:0'],
            'low_stock_threshold' => ['required', 'numeric', 'min:0'],
        ];
    }
}
