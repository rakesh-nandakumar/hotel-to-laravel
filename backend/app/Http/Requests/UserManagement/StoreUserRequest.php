<?php

namespace App\Http\Requests\UserManagement;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('user_management_users.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => [
                'required',
                'confirmed',
                'max:128',
                Password::min(12)->mixedCase()->numbers()->uncompromised(),
            ],
            'status' => ['required', Rule::in(User::STATUSES)],
            'two_factor_required' => ['sometimes', 'boolean'],
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
            'warehouse_ids' => ['array'],
            'warehouse_ids.*' => ['integer', 'exists:warehouses,id'],
        ];
    }
}
