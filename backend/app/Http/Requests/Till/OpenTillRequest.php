<?php

namespace App\Http\Requests\Till;

use Illuminate\Foundation\Http\FormRequest;

class OpenTillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('till.open') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'till_id' => ['required', 'integer', 'exists:tills,id'],
            'opening_balance' => ['required', 'integer', 'min:0'],
        ];
    }
}
