<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\CurrentContext;
use App\Services\TenantHostResolver;
use App\Support\PublicBranding;
use App\Support\TenantReachability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The SPA's boot gate: tells the frontend, before anything else mounts,
 * whether this host is master control or a tenant — and if a tenant, its
 * full public branding (name, logo, theme colours) so the login screen can
 * render without a second round-trip.
 *
 * nginx proxies every page load through this endpoint (auth_request /_host_check);
 * a 404 here means the site is served the plain unavailable page instead of
 * the app bundle. Same resolution rules as IdentifyTenant — a host that
 * resolves a tenant here must resolve the identical tenant there.
 */
class HostContextController extends Controller
{
    public function __invoke(Request $request, TenantHostResolver $resolver): JsonResponse
    {
        $context = $resolver->resolve((string) $request->getHost());

        if ($context->isCentral()) {
            return response()->json(['mode' => 'central']);
        }

        if ($context->isUnknown()) {
            abort(404);
        }

        $tenant = Tenant::query()->where('slug', $context->slug())->first();

        if (! $tenant || ! TenantReachability::check($tenant)) {
            abort(404);
        }

        // Settings reads through the ambient tenant context — adopt this
        // tenant for the rest of the request, exactly as IdentifyTenant does.
        app(CurrentContext::class)->setTenant($tenant->id);

        return response()->json([
            'mode' => 'tenant',
            ...PublicBranding::payload(),
        ]);
    }
}
