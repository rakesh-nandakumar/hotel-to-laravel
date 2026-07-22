<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MenuRenderer;
use App\Services\TenantContext;
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
    public function __construct(private readonly TenantContext $context) {}

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
            'tenant' => [
                'id' => $this->context->tenantId(),
                'name' => $this->context->tenant()?->name,
                'slug' => $this->context->tenant()?->slug,
            ],
            'impersonating' => $request->session()->has('impersonating_from'),
        ]);
    }
}
