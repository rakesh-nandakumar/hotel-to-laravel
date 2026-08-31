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
        // real request to /{slug}/… would, and give every request the same
        // tenant's X-Tenant-Slug header (see IdentifyTenant) the way a browser
        // at /default/… does, instead of relying on the host fallback and the
        // demo subdomain. Tests that deliberately exercise host resolution,
        // master control or another tenant clear it with
        // $this->withoutHeader('X-Tenant-Slug'). Skipped for pure unit tests,
        // which have no database.
        if (Schema::hasTable('tenants')) {
            $demo = Tenant::demo();
            app(CurrentContext::class)->setTenant($demo->id);
            $this->withHeader('X-Tenant-Slug', $demo->slug);
        }
    }
}
