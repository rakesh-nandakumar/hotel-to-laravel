<?php

namespace App\Http\Requests\Hotel;

use Illuminate\Foundation\Http\FormRequest;

class CompleteHousekeepingTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('hotel_housekeeping.complete') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'checklist' => ['nullable', 'array'],
            'checklist.*.item' => ['required_with:checklist', 'string'],
            'checklist.*.done' => ['required_with:checklist', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
