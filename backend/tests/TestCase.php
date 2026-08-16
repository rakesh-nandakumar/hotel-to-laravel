<?php

namespace Tests;

use App\Models\Tenant;
use App\Services\CurrentContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every route lives under the stateful `api` middleware group, which
        // only boots the session for requests Sanctum recognises as coming
        // from the first-party SPA (matched by Referer/Origin against
        // config('sanctum.stateful')). A real browser always sends one; the
        // test client doesn't unless told to, so every test gets one here.
        $this->withHeader('Referer', config('app.url'));

        // Test setup runs in console, where TenantScope would otherwise fail
        // loud on every scoped query (see TenantScope). Mirror production's
        // resolved-context behaviour: bind the demo tenant so console setup —
        // factories, helpers, seeders — stamps and reads rows exactly like a
        // real request to {slug}.localhost would. Tests that need a different
        // tenant override it with CurrentContext::setTenant() /
        // runForTenant(); cross-tenant sweeps opt out with runWithoutTenant().
        // Skipped for pure unit tests, which have no database.
        if (Schema::hasTable('tenants')) {
            app(CurrentContext::class)->setTenant(Tenant::demo()->id);
        }
    }
}
