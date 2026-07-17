<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class StoreMenuCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_menu_categories.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', 'unique:pos_menu_categories,name'],
            'sort_order' => ['nullable', 'integer'],
            'is_minibar' => ['nullable', 'boolean'],
        ];
    }
}
