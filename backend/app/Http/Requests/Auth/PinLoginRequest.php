<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class PinLoginRequest extends FormRequest
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
            'device_token' => ['required', 'string', 'min:10'],
            'pin' => ['required', 'string', 'min:4', 'max:6'],
        ];
    }
}
