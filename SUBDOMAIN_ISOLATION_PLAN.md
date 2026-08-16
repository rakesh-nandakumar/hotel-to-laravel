# Subdomain Isolation — Implementation Plan

Fixing two defects: `admin.` renders a tenant app instead of master control, and
unregistered subdomains render a working-looking site instead of nothing.

---

## 1. What is actually wrong

### 1.1 The admin subdomain shows a tenant login

`web/src/lib/tenancy.ts` decides which app to mount using a **build-time
constant**:

```ts
export const BASE_DOMAIN = (import.meta.env.VITE_TENANCY_BASE_DOMAIN ?? "").trim().toLowerCase();

export function isCentralHost(hostname = window.location.hostname): boolean {
  if (!BASE_DOMAIN) return false;                       // ← silent false
  const host = hostname.toLowerCase();
  return host === BASE_DOMAIN || host === `${CENTRAL_SUBDOMAIN}.${BASE_DOMAIN}`;
}
```

`main.tsx` then does `isCentral ? <CentralApp /> : <App />`.

The value compiled into the shipped bundle is `hms.vellixglobal.com` — confirmed
by grepping `web/dist/assets/*.js`:

```
const fa="hms.vellixglobal.com".trim().toLowerCase(), pp="admin".trim().toLowerCase()
```

So on `admin.vellixglobal.com`, `isCentralHost()` compares against
`hms.vellixglobal.com` and `admin.hms.vellixglobal.com`. Both miss. It returns
`false`, and **`<App />` — the tenant app — mounts**. That is precisely the
"generic website login" you're seeing.

There is no error, no warning, no blank screen. A domain mismatch degrades
silently into serving the wrong application. That is the core design flaw:
`BASE_DOMAIN` is a hardcoded literal that must be kept in sync by hand, and
nothing detects when it isn't.

**The drift is already in the repo.** `MASTER_CONTROL.md` documents
`admin.vellixglobal.com`; `backend/.env.production` sets
`TENANCY_BASE_DOMAIN=hms.vellixglobal.com`. Two sources of truth, disagreeing.

### 1.2 Any subdomain renders a site

`web/nginx.conf` is a catch-all that serves the SPA to every hostname:

```nginx
server { listen 80; server_name _;
  location / { try_files $uri $uri/ /index.html; }
}
```

Nothing consults the tenant list before handing over the bundle. The browser
downloads the whole app, mounts `<App />`, paints the login shell, and falls back
to hardcoded defaults for branding (`"Mount View Hotel"` in
`Hotel/PublicController::branding`). The API calls behind it 404 — but the site
is visibly *there*, which is what you're objecting to.

### 1.3 The status check is a denylist

`IdentifyTenant` (line 69):

```php
if ($tenant->status === TenantStatus::SUSPENDED) {
    throw new NotFoundHttpException('This account is suspended.');
}
```

Only `suspended` is blocked. **Everything else passes** — including a trial that
expired eighteen months ago, or any status string that isn't one of the three
constants. `trial_ends_at` exists on the model and is cast to `datetime`, and it
is never checked anywhere in the resolution path.

### 1.4 `dev_fallback` is a live privilege-escalation path — highest severity

```php
'dev_fallback' => env('APP_ENV') !== 'production',   // config/tenancy.php:37
```

This is **on by default for every environment that isn't literally
`production`** — and your current `backend/.env` has `APP_ENV=local`. When on, two
things happen in `IdentifyTenant`:

```php
$isCentralPath = config('tenancy.dev_fallback') && $request->is('api/central', 'api/central/*');
if ($host === $baseDomain || $host === $centralHost || $isCentralPath) {
    return $next($request);          // central context, NO tenant resolved
}
```

Any host at all — `acme.example.com`, `whatever.example.com` — requesting
`/api/central/*` short-circuits into central context. `EnsureCentralContext` only
rejects when a tenant *was* resolved (`tenantId() !== null`); here none was, so it
passes. **Master control becomes reachable from a tenant subdomain.**

Second, `devFallback()` ends with:

```php
return Tenant::query()->count() === 1 ? Tenant::query()->first() : null;
```

Any host that doesn't parse as a subdomain resolves to "the only tenant in the
database" and serves its real data. This is the "I see a lot of data" symptom.

If `APP_ENV` is ever not `production` on a deployed box, both holes are open.

---

## 2. Design: relative host resolution

You asked whether the rules can apply *relatively* to whatever domain the app is
deployed under, rather than being pinned to one literal. Yes — and it is strictly
better, because it deletes the entire class of bug in §1.1.

**Rule: the first DNS label is the identity. Everything after it is the base.**

| Host | First label | Base | Resolves to |
|---|---|---|---|
| `admin.vellixglobal.com` | `admin` | `vellixglobal.com` | Central |
| `admin.hms.vellixglobal.com` | `admin` | `hms.vellixglobal.com` | Central |
| `acme.anything.tld` | `acme` | `anything.tld` | Tenant `acme` |
| `admin.localhost` | `admin` | `localhost` | Central |
| `vellixglobal.com` | — | — | Apex (see below) |

No domain literal anywhere. Deploy the same artifact under any wildcard domain
and it self-configures from the `Host` header. The only configured value is the
reserved central label (`admin`), which already has a sane default.

**Consequence worth noting:** the SPA no longer needs `VITE_TENANCY_BASE_DOMAIN`
at build time, so **one `dist/` works on every deployment**. The
"wrong-domain-baked-into-the-bundle" failure becomes impossible rather than
merely unlikely.

### The two edge cases this must handle

**Apex.** `vellixglobal.com` naively splits to first label `vellixglobal`, base
`com` — read as a tenant slug. Guard: require the base to have at least
`TENANCY_MIN_BASE_LABELS` labels (default `2`). With base `com` (1 label) the host
is recognised as the apex, not a tenant. Deployments on a multipart TLD
(`example.co.uk`) set this to `3`.

**`localhost`.** `acme.localhost` has base `localhost` — 1 label, which the rule
above would reject. Special-case it: when the final label is `localhost`, the
minimum is 1. This keeps your `*.localhost:5173` dev workflow intact.

### Optional strict mode, retained

Keep `TENANCY_BASE_DOMAIN` supported but **optional**. When set, the resolver
additionally asserts the derived base matches it and returns `Unknown` otherwise.
Unset → relative mode. This gives you a belt-and-braces pin for the production
box without reintroducing the hard dependency.

---

## 3. Implementation

### Phase 1 — Backend resolver (authoritative)

**New:** `backend/app/Support/HostContext.php` — a small value object with three
states: `central()`, `tenant(string $slug)`, `unknown()`.

**New:** `backend/app/Services/TenantHostResolver.php`

```php
public function resolve(string $host): HostContext
{
    // 1. normalise: lowercase, strip port, strip trailing dot, IDN → punycode
    // 2. IP literal → unknown
    // 3. split labels; base = everything after the first
    // 4. base label count < minBaseLabels (localhost-aware) → apex → central
    // 5. first label === config('tenancy.central_subdomain') → central
    // 6. strict mode: base !== config('tenancy.base_domain') → unknown
    // 7. otherwise → tenant($firstLabel)
}
```

Pure, no DB, fully unit-testable. Normalisation matters: `getHost()` can carry
case, a trailing dot, or unicode, and a resolver that misses those is bypassable.

**Rewrite:** `backend/app/Http/Middleware/IdentifyTenant.php` to delegate host
parsing to the resolver, and replace the denylist with an allowlist:

```php
$context = $this->resolver->resolve($request->getHost());

if ($context->isCentral()) {
    return $next($request);
}

if ($context->isUnknown()) {
    throw new NotFoundHttpException('Unknown host.');
}

$tenant = Tenant::query()->where('slug', $context->slug())->first();

if (! $tenant || ! $this->isReachable($tenant)) {
    throw new NotFoundHttpException('Unknown host.');   // identical message
}
```

```php
private function isReachable(Tenant $tenant): bool
{
    return match ($tenant->status) {
        TenantStatus::ACTIVE => true,
        TenantStatus::TRIAL  => $tenant->trial_ends_at === null
                             || $tenant->trial_ends_at->isFuture(),
        default              => false,        // suspended, unknown, future states
    };
}
```

Note `default => false`. A status value nobody anticipated now fails closed
instead of open.

**Identical 404 for every rejection.** No tenant, wrong status, expired trial and
malformed host must be indistinguishable. Separate messages let anyone enumerate
your customer list by probing subdomains and reading the error text. This means
deliberately dropping the current `'This account is suspended.'` string.

**Remove** the `$isCentralPath` shortcut entirely (§1.4). With real `*.localhost`
subdomains working locally, it buys nothing and is a privilege-escalation path.

### Phase 2 — Close `dev_fallback`

```php
// config/tenancy.php
'base_domain'        => env('TENANCY_BASE_DOMAIN'),        // now nullable = relative
'central_subdomain'  => env('TENANCY_CENTRAL_SUBDOMAIN', 'admin'),
'min_base_labels'    => (int) env('TENANCY_MIN_BASE_LABELS', 2),
'dev_fallback'       => filter_var(env('TENANCY_DEV_FALLBACK', false), FILTER_VALIDATE_BOOL),
```

Explicit opt-in, defaulting to **false** — not inferred from `APP_ENV`. Then, in
the resolver, refuse to honour it unless the host is genuinely local
(`localhost`, `*.localhost`, `127.0.0.1`, `[::1]`). Even switched on it cannot
affect a real domain.

Drop the "exactly one tenant in the database" rule. It is the mechanism behind
"any subdomain shows real data" and has no remaining purpose.

### Phase 3 — Frontend mirrors the resolver

**Rewrite** `web/src/lib/tenancy.ts` to use the same label logic against
`window.location.hostname`, with no build-time domain. Same apex and `localhost`
rules, so backend and frontend agree by construction.

Delete `VITE_TENANCY_BASE_DOMAIN` from `web/.env`, `web/.env.production`, and from
the env injection in `BuildRelease.php` (around lines 208–216). Keep
`VITE_TENANCY_CENTRAL_SUBDOMAIN`.

Also drop the `/central` path fallback in `shouldMountCentralApp()`. With real
subdomains working in dev it is redundant, and it is a second way to reach the
central panel on a host that shouldn't serve it.

### Phase 4 — Server-authoritative boot gate

Correct client logic still means an unknown subdomain downloads the bundle and
paints *something*. To show nothing, the server has to decide.

**New endpoint** `GET /api/host-context`, unauthenticated, no tenant data:

```json
{ "mode": "central" }
{ "mode": "tenant", "name": "Acme Hotels", "theme_primary": "#0462d3" }
```

404 for anything else. Deliberately minimal — it must not become an oracle for
"does this slug exist" beyond what serving the site already reveals.

`main.tsx` calls it before mounting and renders one of three things: `CentralApp`,
`App`, or the neutral unavailable page. This costs one round trip before first
paint; a small inline "loading" shell covers it.

This also lets you **fold in the branding fetch** the tenant app already makes on
boot, so the round trip is roughly free in practice.

### Phase 5 — Edge hardening

You asked for the maximum feasible, so: Laravel stays authoritative and the edge
becomes a second, independent layer. Two options, in order of preference.

**Option A — `auth_request` (dynamic, no regeneration):**

```nginx
location = /_host_check {
    internal;
    proxy_pass http://api:8000/api/host-context;
    proxy_pass_request_body off;
    proxy_set_header Content-Length "";
    proxy_set_header Host $host;
}

location / {
    auth_request /_host_check;
    error_page 401 403 404 = @unavailable;
    try_files $uri $uri/ /index.html;
}

location @unavailable {
    root /usr/share/nginx/html;
    try_files /unavailable.html =404;
    internal;
}
```

Always current, no cache to invalidate. Add `proxy_cache` with a short TTL on the
subrequest if the extra hop per asset matters.

**Option B — generated host allowlist:** an artisan command writes an `nginx map`
of valid hosts, re-run on tenant create/suspend/status change, `nginx -s reload`.
Zero per-request latency, but it is stale between regenerations — a suspension
doesn't take effect until the hook fires. Only pick this if the subrequest
overhead proves real.

**The unavailable page** (`web/public/unavailable.html`) is plain text on a white
background, served with `404`. No logo, no product name, no "Vellix", no link, no
mention that a platform exists. Something like *"This site isn't available."* and
nothing more. Identical for unregistered, suspended and expired — so it can't be
used to enumerate accounts.

Also set `server_name _;` to `return 444` for requests with **no** `Host` header,
and ensure the wildcard TLS cert doesn't itself leak the tenant list (it won't —
`*.domain` covers all).

### Phase 6 — Reserved slugs

`Central/TenantController` currently blocks only the central subdomain, at line 47:

```php
if (Str::lower($data['slug']) === Str::lower(config('tenancy.central_subdomain'))) {
```

Replace with a `ReservedSlug` validation rule covering at minimum: `admin`,
`central`, `www`, `api`, `app`, `mail`, `smtp`, `ftp`, `cdn`, `static`, `assets`,
`status`, `help`, `support`, `billing`, `dashboard`.

Two things I checked that turned out to be **fine already**, so no work needed:

- `update()` does not accept `slug` at all — it validates only `name`, `status`
  and `trial_ends_at`. A tenant cannot be renamed into `admin` post-creation.
- `slug` already has a database-level unique index
  (`$table->string('slug', 63)->unique()` in `create_tenants_table`), so the
  `Rule::unique` at line 38 is backed by a real constraint, not just an
  application check.

One thing the migration *does* confirm is worth acting on: `status` is
`string(20)` with an index — a free-text column, not a DB enum. Nothing at the
storage layer prevents an unexpected value being written, which is exactly why
the `default => false` arm in `isReachable()` matters rather than being
defensive boilerplate.

---

## 4. Tests

Extend `backend/tests/Feature/Central/CentralHostResolutionTest.php` — it already
has the right shape, including the `centralUrl()` / `tenantUrl()` helpers and the
comment explaining that absolute URLs are required because Laravel derives
`HTTP_HOST` from the URL, not from a `Host` header.

New unit tests for `TenantHostResolver`:

- apex → central; `admin.{base}` → central at 2, 3 and 4 label depths
- `acme.{base}` → tenant `acme`, under two *different* base domains in the same
  test run — this is the assertion that proves relativity
- `ACME.Example.COM.` (case, trailing dot) → tenant `acme`
- punycode / unicode host normalisation
- IP literal → unknown
- `min_base_labels = 3` for `.co.uk`
- strict mode rejects a mismatched base

New feature tests:

- expired trial → 404; trial with future `trial_ends_at` → 200; `null`
  `trial_ends_at` → 200
- unknown slug, suspended tenant and expired trial all return the **same** body
- soft-deleted tenant → 404
- `/api/central/*` from a tenant host → 404 **with `dev_fallback` on**, which is
  the regression test for §1.4
- `dev_fallback` is off unless explicitly enabled, and is ignored on a non-local
  host

Update the existing `beforeEach`, which sets `config()->set('tenancy.dev_fallback', false)` —
once the default flips to false this becomes redundant, but the tests that
*exercise* the fallback need to set it true explicitly.

---

## 5. Sequencing

| # | Work | Risk | Depends on |
|---|---|---|---|
| 1 | `TenantHostResolver` + `HostContext` + unit tests | None — new code | — |
| 2 | Rewrite `IdentifyTenant`, status allowlist | Medium | 1 |
| 3 | Close `dev_fallback`, drop central-path shortcut | Medium | 2 |
| 4 | Frontend relative resolution | Low | 1 |
| 5 | `/api/host-context` + boot gate | Low | 2 |
| 6 | nginx `auth_request` + unavailable page | Medium — infra | 5 |
| 7 | Reserved slugs | Low | — |
| 8 | Reconcile `MASTER_CONTROL.md`, `.env*`, `BuildRelease` | Low | 3, 4 |

Steps 1–3 alone fix the security problem. Steps 4–6 fix what the browser shows.

**Deployment order matters.** Ship the backend first and the frontend second: an
old bundle against the new backend degrades to failing API calls, which is safe.
The reverse — new bundle, old backend — leaves the `/api/central/*` shortcut open
while the SPA has stopped defending against it.

### Before you start

- `backend/.env` has `APP_ENV=local`. If any deployed instance shares that,
  §1.4 is open right now — check that first.
- Confirm which base domain is live (`hms.vellixglobal.com` per
  `.env.production`, or `vellixglobal.com` per the docs). Relative resolution
  makes this a documentation question rather than a functional one, but the docs
  should say the true thing.
- `MASTER_CONTROL.md` §7 currently documents `admin.` showing the tenant app as a
  build-config mistake. After this change that row describes an impossible state
  and should be removed.
