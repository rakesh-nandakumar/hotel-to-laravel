<?php

namespace App\Http\Requests\Till;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('till.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
