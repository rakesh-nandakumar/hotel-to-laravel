<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AuditLog;
use App\Services\Impersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function __construct(private readonly Impersonation $impersonation) {}

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $admin = $request->user('central');

        ['url' => $url, 'user' => $user] = $this->impersonation->startFor($tenant, $admin);

        AuditLog::record('impersonation.started', $tenant, [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'central_admin' => $admin->email,
        ], Auth::guard('web')->id());

        return response()->json(['url' => $url]);
    }
}
