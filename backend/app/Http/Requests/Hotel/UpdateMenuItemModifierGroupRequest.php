<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMenuItemModifierGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_menu_items.edit') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'is_required' => ['sometimes', 'boolean'],
            'max_select' => ['sometimes', 'integer', 'min:1'],
            'sort_order' => ['sometimes', 'integer'],
        ];
    }
}
