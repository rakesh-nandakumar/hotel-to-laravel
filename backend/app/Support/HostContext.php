<?php

namespace App\Support;

/**
 * The three outcomes of resolving a Host header (see TenantHostResolver):
 *
 *   - central:  master control — the bare base domain (apex) or the reserved
 *               central subdomain. No tenant is resolved.
 *   - tenant:   a concrete tenant subdomain, named by its first label.
 *   - unknown:  a host that must not be served at all (IP literal, a base
 *               that doesn't match the pinned TENANCY_BASE_DOMAIN, ...).
 */
final class HostContext
{
    private function __construct(
        private readonly ?string $slug,
        private readonly bool $central,
        private readonly bool $unknown,
    ) {}

    public static function central(): self
    {
        return new self(null, true, false);
    }

    public static function tenant(string $slug): self
    {
        return new self($slug, false, false);
    }

    public static function unknown(): self
    {
        return new self(null, false, true);
    }

    public function isCentral(): bool
    {
        return $this->central;
    }

    public function isUnknown(): bool
    {
        return $this->unknown;
    }

    public function isTenant(): bool
    {
        return ! $this->central && ! $this->unknown;
    }

    public function slug(): string
    {
        return $this->slug ?? '';
    }
}
