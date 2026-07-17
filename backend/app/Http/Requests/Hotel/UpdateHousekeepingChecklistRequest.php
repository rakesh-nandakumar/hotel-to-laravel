<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHousekeepingChecklistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_housekeeping.checklist') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'checklist' => ['required', 'array'],
            'checklist.*.item' => ['required', 'string'],
            'checklist.*.done' => ['required', 'boolean'],
        ];
    }
}
