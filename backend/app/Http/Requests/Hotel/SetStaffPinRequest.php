<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class SetStaffPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_staff.set_pin') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Omit/null clears the PIN (disables PIN quick-login for that staff member).
            'pin' => ['nullable', 'string', 'regex:/^\d{4,6}$/'],
        ];
    }
}
