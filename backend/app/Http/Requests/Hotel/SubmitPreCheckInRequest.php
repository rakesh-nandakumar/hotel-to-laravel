<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class SubmitPreCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:3'],
            'id_number' => ['required', 'string', 'min:3'],
            'full_name' => ['required', 'string'],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'string'],
            'nationality' => ['nullable', 'string'],
            'eta' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
