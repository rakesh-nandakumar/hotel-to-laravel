<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\SetStaffPinRequest;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    /**
     * Lightweight active-staff directory (id/name/roles) for "assign to"
     * pickers across housekeeping, maintenance, audit-log filters, etc.
     * Deliberately ungated beyond authentication — ported from the Node
     * app's GET /auth/staff-list, which any signed-in user could call.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'staff' => User::query()
                ->where('status', User::STATUS_ACTIVE)
                ->with('roles:id,name')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /** Set (or clear) a staff member's PIN quick-unlock code. Ported from the Node app's staff.ts `pin` field. */
    public function setPin(SetStaffPinRequest $request, User $user): JsonResponse
    {
        $pin = $request->validated('pin');

        $user->update(['pin_hash' => $pin ? Hash::make($pin) : null]);

        AuditLog::record($pin ? 'staff.pin_set' : 'staff.pin_cleared', $user);

        return response()->json(['message' => $pin ? 'PIN set.' : 'PIN cleared.']);
    }
}
