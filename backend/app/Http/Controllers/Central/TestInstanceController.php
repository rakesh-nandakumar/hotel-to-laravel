<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AuditLog as AuditLogService;
use App\Services\CurrentContext;
use App\Services\Tenancy\TestInstanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Test instances (Master Control): create / sync / destroy an isolated copy of
 * a live tenant. See App\Services\Tenancy\TestInstanceService for the clone
 * engine and config/tenancy-clone.php for the schema topology.
 */
class TestInstanceController extends Controller
{
    public function __construct(private readonly TestInstanceService $service) {}

    /**
     * Every test instance on the platform, with its live parent.
     */
    public function index(): JsonResponse
    {
        $instances = Tenant::query()
            ->where('environment', Tenant::ENV_TEST)
            ->with('parentTenant:id,name,slug')
            ->latest()
            ->get();

        return response()->json(['instances' => $instances]);
    }

    /**
     * The test instance of a live tenant, if one exists.
     */
    public function show(Tenant $tenant): JsonResponse
    {
        $instance = $tenant->testInstance;

        return response()->json(['instance' => $instance]);
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        abort_if($tenant->environment !== Tenant::ENV_LIVE, 422, 'A test instance cannot have its own test instance.');
        abort_if($tenant->testInstance !== null, 422, 'This tenant already has a test instance.');

        try {
            $instance = $this->service->create($tenant, $request->user('central')->id);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        $this->recordAudit($tenant, $request, 'test_instance.created', [
            'slug' => $instance->slug,
        ]);

        return response()->json(['instance' => $instance], 201);
    }

    public function sync(Request $request, Tenant $tenant): JsonResponse
    {
        $instance = $this->testInstanceOf($tenant);

        try {
            $result = $this->service->syncFromLive($instance, $request->user('central')->id);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        $this->recordAudit($tenant, $request, 'test_instance.synced', [
            'slug' => $instance->slug,
            'total_rows' => $result['total_rows'],
        ]);

        return response()->json(['instance' => $instance->refresh(), 'summary' => $result]);
    }

    public function destroy(Request $request, Tenant $tenant): JsonResponse
    {
        $instance = $this->testInstanceOf($tenant);

        $this->service->destroy($instance);

        $this->recordAudit($tenant, $request, 'test_instance.destroyed', [
            'slug' => $instance->slug,
        ]);

        return response()->json(['message' => 'Test instance deleted.']);
    }

    private function testInstanceOf(Tenant $tenant): Tenant
    {
        $instance = $tenant->testInstance;
        abort_unless($instance !== null, 404, 'This tenant has no test instance.');

        return $instance;
    }

    private function recordAudit(Tenant $tenant, Request $request, string $action, array $context = []): void
    {
        app(CurrentContext::class)->runForTenant($tenant->id, function () use ($tenant, $request, $action, $context): void {
            AuditLogService::record(
                $action,
                $tenant,
                [...$context, 'central_admin' => $request->user('central')->email],
                Auth::guard('web')->id(),
            );
        });
    }
}
