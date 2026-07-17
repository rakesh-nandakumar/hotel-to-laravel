<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
    }
}
