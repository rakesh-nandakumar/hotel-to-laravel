<?php

namespace App\Http\Requests\Hotel;

use App\Models\Lookup;
use App\Support\Lookups\LookupType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoomStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Node grants this specifically to MANAGER *or* HOUSEKEEPER — narrower
        // than full room editing, which is Manager/Owner only.
        return $this->user()?->hasPermissionTo('hotel_rooms.edit_status') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::exists('lookups', 'code')->where('type', LookupType::ROOM_STATUS),
            ],
        ];
    }

    public function statusLookup(): Lookup
    {
        return Lookup::query()->type(LookupType::ROOM_STATUS)->where('code', $this->string('status'))->firstOrFail();
    }
}
