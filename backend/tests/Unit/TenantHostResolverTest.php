<?php

use App\Services\TenantHostResolver;
use App\Support\HostContext;

/**
 * resolveFromSlug() is the primary identity (X-Tenant-Slug header / `tenant`
 * query parameter) — most of this suite covers it. resolve() is the HOST
 * fallback kept for the dual-mode window in which old {slug}.{base} URLs are
 * still 301'd; its rules are tested below and deleted together with it at
 * cutover.
 */
beforeEach(function () {
    // Relative mode by default — no pinned base domain.
    config()->set('tenancy.base_domain', null);
    config()->set('tenancy.central_prefix', 'admin');
    config()->set('tenancy.min_base_labels', 2);
});

function resolveHost(string $host): HostContext
{
    return app(TenantHostResolver::class)->resolve($host);
}

function resolveSlug(string $slug): HostContext
{
    return app(TenantHostResolver::class)->resolveFromSlug($slug);
}

it('resolves a bare slug to its tenant, normalised', function () {
    expect(resolveSlug('wasana')->isTenant())->toBeTrue()
        ->and(resolveSlug('wasana')->slug())->toBe('wasana')
        ->and(resolveSlug('  Acme.  ')->slug())->toBe('acme')
        ->and(resolveSlug('acme.')->slug())->toBe('acme');
});

it('never grants central from a slug — it fails closed instead', function () {
    expect(resolveSlug(config('tenancy.central_prefix'))->isUnknown())->toBeTrue()
        ->and(resolveSlug('admin')->isUnknown())->toBeTrue();
});

it('rejects an empty slug as unknown', function () {
    expect(resolveSlug('')->isUnknown())->toBeTrue()
        ->and(resolveSlug('   ')->isUnknown())->toBeTrue();
});

it('treats the bare base domain (apex) as central', function () {
    expect(resolveHost('vellixglobal.com')->isCentral())->toBeTrue()
        ->and(resolveHost('localhost')->isCentral())->toBeTrue();
});

it('treats a short-base host as a tenant under a multipart base', function () {
    // With the default min_base_labels=2, "co.uk" is a valid base — so
    // "example.co.uk" is a tenant subdomain, not the apex.
    expect(resolveHost('example.co.uk')->isTenant())->toBeTrue()
        ->and(resolveHost('example.co.uk')->slug())->toBe('example');
});

it('treats the central subdomain as central at 2, 3 and 4 label depths', function () {
    expect(resolveHost('admin.vellixglobal.com')->isCentral())->toBeTrue()
        ->and(resolveHost('admin.hms.vellixglobal.com')->isCentral())->toBeTrue()
        ->and(resolveHost('admin.api.hms.vellixglobal.com')->isCentral())->toBeTrue()
        ->and(resolveHost('admin.localhost')->isCentral())->toBeTrue();
});

it('resolves a tenant subdomain relatively under two different base domains', function () {
    // Same artifact, two unrelated domains — this is the assertion that proves
    // relativity: no domain literal participates in the decision.
    expect(resolveHost('acme.vellixglobal.com')->isTenant())->toBeTrue()
        ->and(resolveHost('acme.vellixglobal.com')->slug())->toBe('acme')
        ->and(resolveHost('acme.any-other-domain.example')->isTenant())->toBeTrue()
        ->and(resolveHost('acme.any-other-domain.example')->slug())->toBe('acme')
        ->and(resolveHost('acme.localhost')->slug())->toBe('acme');
});

it('normalises case, port and a trailing dot before resolving', function () {
    expect(resolveHost('ACME.Example.COM.')->slug())->toBe('acme')
        ->and(resolveHost('Acme.vellixglobal.com:8443')->slug())->toBe('acme')
        ->and(resolveHost('admin.Example.COM.')->isCentral())->toBeTrue();
});

it('normalises unicode hostnames to punycode', function () {
    // "example.食狮.com.cn" → xn--85x722f
    $context = resolveHost('example.食狮.com.cn');

    expect($context->isTenant())->toBeTrue()
        ->and($context->slug())->toBe('example');
});

it('rejects an IP literal as unknown', function () {
    expect(resolveHost('127.0.0.1')->isUnknown())->toBeTrue()
        ->and(resolveHost('127.0.0.1:8000')->isUnknown())->toBeTrue()
        ->and(resolveHost('192.168.1.10')->isUnknown())->toBeTrue()
        ->and(resolveHost('::1')->isUnknown())->toBeTrue()
        ->and(resolveHost('[::1]')->isUnknown())->toBeTrue();
});

it('rejects an empty or malformed host as unknown', function () {
    expect(resolveHost('')->isUnknown())->toBeTrue()
        ->and(resolveHost('.')->isUnknown())->toBeTrue();
});

it('honours min_base_labels = 3 for a multipart TLD', function () {
    config()->set('tenancy.min_base_labels', 3);

    expect(resolveHost('example.co.uk')->isCentral())->toBeTrue()
        ->and(resolveHost('acme.example.co.uk')->isTenant())->toBeTrue()
        ->and(resolveHost('acme.example.co.uk')->slug())->toBe('acme');
});

it('rejects a base that does not match the pinned base domain', function () {
    config()->set('tenancy.base_domain', 'vellixglobal.com');

    expect(resolveHost('acme.vellixglobal.com')->slug())->toBe('acme')
        ->and(resolveHost('acme.evil-example.com')->isUnknown())->toBeTrue();
});

it('treats the bare host as central when the pinned base domain has 3+ labels', function () {
    // Regression: a pin like "htl.vellixglobal.com" (3 labels) peels down to
    // a remainder ("vellixglobal.com", 2 labels) that isn't short enough to
    // trip the generic apex check, so without a direct pin match the bare
    // pinned host used to misread as tenant slug "htl" and 404.
    config()->set('tenancy.base_domain', 'htl.vellixglobal.com');

    expect(resolveHost('htl.vellixglobal.com')->isCentral())->toBeTrue()
        ->and(resolveHost('admin.htl.vellixglobal.com')->isCentral())->toBeTrue()
        ->and(resolveHost('acme.htl.vellixglobal.com')->isTenant())->toBeTrue()
        ->and(resolveHost('acme.htl.vellixglobal.com')->slug())->toBe('acme')
        ->and(resolveHost('acme.evil-example.com')->isUnknown())->toBeTrue();
});

it('derives the base of the current host for tenant URL building', function () {
    $resolver = app(TenantHostResolver::class);

    expect($resolver->baseOf('admin.vellixglobal.com'))->toBe('vellixglobal.com')
        ->and($resolver->baseOf('vellixglobal.com'))->toBe('vellixglobal.com')
        ->and($resolver->baseOf('acme.localhost'))->toBe('localhost')
        ->and($resolver->baseOf('admin.localhost'))->toBe('localhost')
        ->and($resolver->baseOf('ADMIN.Example.COM.'))->toBe('example.com');
});

it('derives the full pinned base for a multi-label base domain, not the peeled remainder', function () {
    // Same regression as the resolve() pin-match test above: without a direct
    // pin match, "htl.vellixglobal.com" would peel to "vellixglobal.com" and
    // tenant URLs would be built as "acme.vellixglobal.com" instead of
    // "acme.htl.vellixglobal.com".
    config()->set('tenancy.base_domain', 'htl.vellixglobal.com');

    $resolver = app(TenantHostResolver::class);

    expect($resolver->baseOf('htl.vellixglobal.com'))->toBe('htl.vellixglobal.com')
        ->and($resolver->baseOf('admin.htl.vellixglobal.com'))->toBe('htl.vellixglobal.com')
        ->and($resolver->baseOf('acme.htl.vellixglobal.com'))->toBe('htl.vellixglobal.com');
});
