<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CurrentContext;
use App\Services\MenuRenderer;
use App\Services\UserLanding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The SPA's single bootstrap endpoint — replaces what HandleInertiaRequests
 * used to inject into every Inertia page load (auth/menu/branch context).
 * Called once on app mount and again right after login.
 */
class MeController extends Controller
{
    public function __construct(private readonly CurrentContext $context) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user->loadMissing('roles:id,name');
        $primaryRole = $user->roles->first();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->hasVerifiedEmail(),
                'status' => $user->status,
                'phone' => $user->phone,
                'profile_image' => $user->profile_image,
                'two_factor_confirmed' => $user->two_factor_confirmed_at !== null,
                'role' => $primaryRole ? ['id' => $primaryRole->id, 'name' => $primaryRole->name] : null,
                'roles' => $user->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values()->all(),
            ],
            'is_full_admin' => $user->isFullAdmin(),
            'permissions' => $user->cachedPermissionNames()->values()->all(),
            'home' => UserLanding::urlFor($user),
            'menu' => MenuRenderer::forUser($user),
            'branch' => $this->resolveBranchContext($request),
        ]);
    }

    /**
     * @return array{branches: array<int, array{id:int, name:string}>, selected_id: int|null, show_selector: bool}
     */
    private function resolveBranchContext(Request $request): array
    {
        $branches = $this->context->branches();

        $selected = $request->session()->get('selected_branch_id');
        if (! $selected && $branches->count() === 1) {
            $selected = $branches->first()->id;
        }

        return [
            'branches' => $branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'selected_id' => $selected ? (int) $selected : null,
            'show_selector' => $branches->count() > 1,
        ];
    }
}
