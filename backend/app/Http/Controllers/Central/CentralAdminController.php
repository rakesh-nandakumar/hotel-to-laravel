<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\CentralAdmin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform operator management — who may sign in to master control. Mirrors
 * leolanka's CentralAdminResource with the self-lockout guards that resource
 * exists to prevent: you can never disable or delete the account you are
 * currently signed in as, and the last active operator can never be removed.
 */
class CentralAdminController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['admins' => CentralAdmin::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('central_admins', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $admin = CentralAdmin::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['admin' => $admin], 201);
    }

    public function update(Request $request, CentralAdmin $admin): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('central_admins', 'email')->ignore($admin->id)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Self-lockout guard: you can't revoke your own access.
        $isSelf = $admin->id === $request->user('central')->id;
        if ($isSelf && isset($data['is_active']) && $data['is_active'] === false) {
            abort(422, 'You cannot deactivate your own account.');
        }

        // The last active operator is the platform's own lifeline.
        if (! ($data['is_active'] ?? true)
            && $admin->is_active
            && $this->isLastActiveAdmin($admin->id)) {
            abort(422, 'The last active platform operator cannot be deactivated.');
        }

        $admin->update(collect($data)->map(fn ($value) => $value === null ? null : $value)->all());

        return response()->json(['admin' => $admin->refresh()]);
    }

    public function destroy(Request $request, CentralAdmin $admin): JsonResponse
    {
        abort_if($admin->id === $request->user('central')->id, 422, 'You cannot delete your own account.');

        abort_if(
            $admin->is_active && $this->isLastActiveAdmin($admin->id),
            422,
            'The last active platform operator cannot be deleted.',
        );

        $admin->delete();

        return response()->json(['message' => 'Platform operator deleted.']);
    }

    private function isLastActiveAdmin(int $ignoreId): bool
    {
        return CentralAdmin::query()
            ->where('is_active', true)
            ->where('id', '!=', $ignoreId)
            ->doesntExist();
    }
}
