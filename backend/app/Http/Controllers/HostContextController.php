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
 * whether this URL is master control or a tenant — and if a tenant, its
 * full public branding (name, logo, theme colours) so the login screen can
 * render without a second round-trip.
 *
 * Same resolution rules as IdentifyTenant — a request that names a tenant
 * here must resolve the identical tenant there. The SPA reads its own slug
 * from its URL prefix and repeats it in the X-Tenant-Slug header; without
 * one (central panel, old URL style) the Host header resolves instead.
 */
class HostContextController extends Controller
{
    public function __invoke(Request $request, TenantHostResolver $resolver): JsonResponse
    {
        $context = $resolver->resolveRequest($request);

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
