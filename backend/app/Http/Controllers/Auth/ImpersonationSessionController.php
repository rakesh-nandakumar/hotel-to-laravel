<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLog;
use App\Services\CurrentContext;
use App\Services\Impersonation;
use App\Services\UserLanding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Consumes a single-use impersonation token minted from master control (see
 * App\Http\Controllers\Central\ImpersonationController) and establishes a
 * real `web`-guard session for that tenant's user — the platform operator
 * never sees or handles that user's actual password.
 */
class ImpersonationSessionController extends Controller
{
    public function __construct(private readonly Impersonation $impersonation, private readonly CurrentContext $context) {}

    public function store(Request $request, string $token): JsonResponse
    {
        $tenantId = $this->context->tenantId();

        if ($tenantId === null) {
            abort(404);
        }

        $user = $this->impersonation->consume($token, $tenantId);

        if (! $user) {
            abort(401, 'This impersonation link is invalid or has expired.');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        AuditLog::record('user.impersonation_started', $user);

        return response()->json(['home' => UserLanding::urlFor($user)]);
    }
}
