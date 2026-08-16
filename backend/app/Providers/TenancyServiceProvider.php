<?php

namespace App\Providers;

use App\Services\CurrentContext;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Carries the tenant across the queue boundary. Every dispatched job/mail/
 * notification payload is stamped with the current tenant_id, and the worker
 * re-binds that tenant before running the job (or marks central when absent),
 * so queued work is subject to the same {@see TenantScope}
 * isolation as the web request that enqueued it. No per-job wiring required.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Queue::createPayloadUsing(function (): array {
            $tenantId = app(CurrentContext::class)->tenantId();

            return $tenantId !== null ? ['tenant_id' => $tenantId] : [];
        });

        Queue::before(function (JobProcessing $event): void {
            $context = app(CurrentContext::class);
            $tenantId = $event->job->payload()['tenant_id'] ?? null;

            if ($tenantId !== null) {
                $context->setTenant((int) $tenantId);
            } else {
                $context->markCentral();
            }
        });
    }
}
