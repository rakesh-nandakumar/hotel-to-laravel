# Master Control — User Guide

Master control is the platform-operator panel that owns every tenant on the
system: it creates them, decides which modules each one has licensed, edits
their settings, and lets you log into any of them without a password.

One codebase serves two different apps, chosen by hostname:

| Host | What loads | Who signs in |
| --- | --- | --- |
| `admin.{base}` | Master control | Platform operator (`central_admins`) |
| `{slug}.{base}` | That tenant's hotel/apartment/restaurant app | That tenant's staff (`users`) |

Where `{base}` is everything after the **first label** of the host you typed
(resolution is relative — see §1): on `admin.hms.vellixglobal.com` the base is
`hms.vellixglobal.com` and the panel loads; on `acme.hms.vellixglobal.com` the
same label logic loads tenant `acme`. The bare base domain itself counts as
central too.

These are separate login systems. A platform operator is **not** a user of any
tenant, holds no permissions inside one, and is never subject to tenant
scoping. A tenant's staff can never reach master control — requesting
`/api/central/*` from a tenant subdomain returns 404 even with a valid
operator session.

> Page loads on hosts that own nothing (unknown subdomain, suspended tenant,
> expired trial) are answered with a plain *"This site isn't available."* page
> before the app bundle is even downloaded: nginx gates every request through
> the backend's `/api/host-context` (`auth_request`, see `web/nginx.conf`), and
> only resolves hosts get the SPA.

---

## 1. First-time setup

### DNS and TLS

Point the one origin host at the server with an SSL cert for it
(a wildcard `*.vellixglobal.com` cert remains needed **only while old
subdomain URLs still arrive** during cutover — see *Cutover* below):

```
vellixglobal.com    A     <server-ip>
```

Tenancy is **path-prefix**: every tenant lives at
`https://vellixglobal.com/{slug}/…` and master control at
`https://vellixglobal.com/admin`. No DNS change is needed when you add a
tenant.

### Backend environment

In `backend/.env`:

```dotenv
APP_URL=https://vellixglobal.com
TENANCY_CENTRAL_PREFIX=admin        # (default; omit if you keep "admin")

FRONTEND_URL=https://vellixglobal.com
SANCTUM_STATEFUL_DOMAINS=vellixglobal.com
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

Identity is **header-based**: the SPA reads its slug from its own URL prefix
(`/wasana/…` → slug `wasana`) and sends it as `X-Tenant-Slug` on every API
call; `IdentifyTenant` resolves the tenant from it before anything else runs.
The bare host request (no header) is central. One origin means one cookie
jar — the same session store holds tenant users and platform operators.

Transitional leftovers while downstream consumers (printed QR codes, old
bookmarks, wildcard DNS) age out — remove at cutover:

- `TENANCY_BASE_DOMAIN=vellixglobal.com` — feeds the **Host fallback**: a
  request with no header still resolves `{slug}.{base}` the old way.
- `SANCTUM_STATEFUL_DOMAINS=vellixglobal.com,*.vellixglobal.com` — the
  wildcard covers old subdomain page loads' sessions.

> **Leave `SESSION_DOMAIN` unset.** One origin, one cookie — and
> `IdentifyTenant`'s cross-tenant guard is what binds a session to the tenant
> the URL names (a mismatch 401s and logs the session out; it never lets data
> flow between tenants). Two tenants in one browser = one active tenant
> session, by design.

### Frontend environment

The SPA needs **no tenancy environment** — one build serves every tenant
prefix and the panel. At runtime the bundle reads its slug from its own path
and asks the backend which shell this URL is (`/api/host-context`, with
`X-Tenant-Slug` set for tenant paths) before mounting anything; for a tenant
the same payload IS its branding (name, logo, theme), so the login screen
renders with no second round-trip (see `web/src/lib/tenancy.ts`).

```bash
cd web && npm run build
```

Serve `dist/` from the one host, with `/api` and `/sanctum` passed through to
Laravel on that same origin, and every other request served the SPA shell
(no host gate needed anymore — the boot gate lives in the SPA). Master
control is served **only** at `/{TENANCY_CENTRAL_PREFIX}` — a tenant prefix
can never render it (see `web/nginx.conf`).

### Create the first platform operator

The demo seeder refuses to run in production, so create the operator
explicitly:

```bash
cd backend && php artisan central:create-admin
```

It prompts for name, email and password (min 8 characters), or accepts
`--name`, `--email` and `--password` for scripted deploys. Run it again for
each additional operator.

---

## 2. Local development

Local dev is **path-prefix over one origin**, exactly like production — no
subdomains, no hosts file, no DNS:

| | URL |
| --- | --- |
| Master control | http://localhost:5173/admin |
| A tenant's app | http://localhost:5173/{slug}/… |

This relies on two things, all already configured:

1. `TENANCY_CENTRAL_PREFIX=admin` in `backend/.env` (the default).
2. **`changeOrigin: false`** on the Vite proxy (`web/vite.config.ts`).
   Tenant requests carry `X-Tenant-Slug`, so they resolve from the header
   either way — but master control requests carry **no** header and are
   resolved from the Host. `changeOrigin: true` would rewrite the Host to the
   proxy target's `127.0.0.1`, an IP literal that resolves nothing, killing
   the panel (`{"message":"Unknown host."}`).

Don't browse the old `{slug}.localhost:5173` style on purpose — it still
resolves during the cutover window via the Host fallback, but it's the old
shape.

Seed the demo data (`php artisan migrate:fresh --seed`) and sign in with:

- **Platform operator** — `admin@vellix.com` / `password`
  (from `CentralAdminSeeder`; run it alone with
  `php artisan db:seed --class=CentralAdminSeeder`)
- **Tenant staff** — `admin@vellix.com` / `password`
  (from `AdminUsersSeeder`)

> Those are the **same email and password** but two completely different
> accounts, in two different tables, behind two different guards. Which one you
> get depends only on which login page you use: master control signs you in as
> the platform operator, a tenant's own login signs you in as that tenant's
> staff. Change one of them locally if the ambiguity bites — nothing depends on
> the values.

The `?tenant=` / `X-Tenant-Slug` fallback is strictly dev-only (it requires
`TENANCY_DEV_FALLBACK=true`). In production every host resolves purely from
its own Host header.

---

## 3. Creating a tenant

**Tenants → New tenant.** You provide:

| Field | Notes |
| --- | --- |
| Business name | Display name, e.g. "Acme Hotels" |
| Subdomain | Lowercase letters, numbers and dashes. Becomes `{slug}.{base}`. Infrastructure labels (`admin`, `www`, `api`, `app`, `mail`, `smtp`, `ftp`, `cdn`, `static`, `assets`, `status`, `help`, `support`, `billing`, `dashboard`, `central`) are reserved and rejected |
| Admin email | The Full Administrator account created for this tenant |
| Admin name | Optional; defaults to "{Business} Admin" |

Creating a tenant provisions everything the business needs in one transaction:

1. Its own private copy of the baseline system roles (Full Administrator,
   Manager, Housekeeper, …).
2. **All five modules enabled** by default — trim them afterwards.
3. One Full Administrator user with the email you supplied.

That admin user is created with a random password that is never displayed or
sent anywhere. This is deliberate: **impersonation is the intended way in**,
so no shared secret for the tenant's most privileged account ever exists. If
the business wants their own direct login, have them use password reset.

### Status

- **Trial** — default for a new tenant; set `trial_ends_at` to track it.
- **Active** — a paying tenant.
- **Suspended** — the subdomain immediately returns 404 for everyone. Data is
  untouched and flipping back to active restores access.

---

## 4. Modules

**Tenant → Modules** licenses coarse feature groups per tenant:

| Module | Screens | Permissions | Covers |
| --- | ---: | ---: | --- |
| Hotel Operations | 16 | 75 | Rooms, reservations, guests, housekeeping, maintenance, laundry, venues, front-desk reports |
| Restaurant / POS | 7 | 49 | Menu, ordering, dining tables, QR ordering, restaurant reports |
| Apartments | 11 | 53 | Properties, units, leases, sales, tenant billing, reports |
| Payroll | 1 | 9 | Pay runs, deductions, payslips |
| Till & Cash Management | 1 | 7 | Cash drawer sessions, cash in/out, reconciliation |
| *(core — not licensable)* | 5 | 20 | Dashboard, user management, roles, audit logs, staff PIN |

That's **213 permissions total**. Toggling a module takes effect on the
tenant's next request. A disabled module disappears from their menu and its
endpoints return 403.

### How modules and permissions relate

These are two independent layers, and **both** must pass:

```
request → does the user's ROLE grant {module_key}.{action}?   ← tenant decides
        → is that module_key's group LICENSED to the tenant?  ← you decide
        → allowed
```

A permission is always named `{module_key}.{action}` — e.g.
`hotel_reservations.check_in`. The part before the dot is the screen; the part
after is what you can do on it. Enforcement is in
[`CheckPermission`](backend/app/Http/Middleware/CheckPermission.php), which
checks the role first and the licence second.

**Licensing outranks the tenant's own permissions.** A tenant's Full
Administrator cannot grant access to a module you haven't licensed — disabled
modules are filtered out of their role-permission matrix entirely, so the
checkboxes don't even appear. This is what makes modules a commercial control
rather than a suggestion.

**Core permissions can never be gated.** `dashboard`, `user_management_users`,
`user_management_roles`, `audit_logs` and `hotel_staff.set_pin` belong to no
module, so they always pass. Otherwise disabling the wrong module would lock a
tenant out of their own login and user administration.

### Exactly what each module grants

Every `{screen}: {actions}` line below is unlocked when you enable that module,
and disappears when you disable it. The tenant's roles then choose which of
these each staff member actually gets.

**Hotel Operations**

| Screen | Actions |
| --- | --- |
| `hotel_rooms` | access, create, edit, edit_status |
| `hotel_room_types` | access, create, edit |
| `hotel_packages` | access, edit |
| `hotel_guests` | access, create, edit, loyalty_adjust, view |
| `hotel_corporate` | access, create, edit |
| `hotel_reservations` | access, cancel, check_in, checkout, create, edit, view |
| `hotel_folios` | add_line, invoice, payment, refund, view, void_line |
| `hotel_housekeeping` | access, assign, checklist, complete, create |
| `hotel_maintenance` | access, create, edit |
| `hotel_laundry` | access, charge, create, edit |
| `hotel_venues` | access, edit |
| `hotel_venue_bookings` | access, cancel, complete, confirm, create, edit, view |
| `hotel_attendance` | access, export, on_duty, view_all |
| `hotel_visitors` | access, create, sign_out |
| `hotel_notifications` | access, run_scheduled, test |
| `hotel_reports` | cancellations, channel_mix, corporate_ar, daily, dashboard, guest_loyalty, laundry, monthly, night_audit_run, night_audit_view, ops_sla, payroll_cost, revpar, venues |

**Restaurant / POS**

| Screen | Actions |
| --- | --- |
| `hotel_menu_categories` | access, create, delete, edit |
| `hotel_menu_items` | access, create, delete, edit, sold_out |
| `hotel_ingredients` | access, adjust_stock, create, delete, edit, write_off |
| `hotel_orders` | access, charge_to_room, create, delivery_dispatch, discount, hold, kot, kot_ticket, merge, receipt, refund, settle, slip, split, view, void, void_item |
| `hotel_dining_tables` | access, create, edit, edit_status |
| `hotel_qr_ordering` | access, create, edit, regenerate |
| `restaurant_reports` | delivery_performance, discounts_voids, food_cost, kitchen_ticket_time, menu_performance, modifiers, pos, shift_sales, table_server |

**Apartments**

| Screen | Actions |
| --- | --- |
| `apartment_properties` | access, create, edit |
| `apartment_unit_types` | access, create, edit |
| `apartment_units` | access, create, edit, edit_status |
| `apartment_customers` | access, create, edit, view |
| `apartment_bookings` | access, cancel, check_in, checkout, create, view |
| `apartment_leases` | access, create, renew, terminate, utility_reading, view |
| `apartment_sales` | access, cancel, complete, create, reserve, sign_agreement, view |
| `apartment_ledgers` | add_line, payment, refund, view, void_line |
| `apartment_housekeeping` | access, assign, checklist, complete, create |
| `apartment_maintenance` | access, create, edit |
| `apartment_reports` | dashboard, occupancy_trend, ops_sla, rent_roll, revenue_channel, sales_pipeline, utilities |

**Payroll**

| Screen | Actions |
| --- | --- |
| `hotel_payroll` | adjust_line, delete_run, export, finalize, generate, manage_pay, mark_paid, payslip, view |

**Till & Cash Management**

| Screen | Actions |
| --- | --- |
| `till` | access, cash_in, cash_out, close, close_any, manage, open |

**Core — always on, not licensable**

| Screen | Actions |
| --- | --- |
| `dashboard` | access |
| `user_management_users` | access, bulk_delete, create, delete, edit, reset_password, unlock, view |
| `user_management_roles` | access, create, delete, duplicate, edit, toggle_active, view |
| `audit_logs` | access, export, view |
| `hotel_staff` | set_pin |

> This table is generated from the live database. To regenerate it after adding
> permissions, iterate `ModuleCatalog::definitions()` and query `permissions`
> for each `module_key` prefix.

---

## 5. Settings

**Tenant → Settings** is where a tenant's configuration lives — currency, tax
rates, branding, document numbering and so on, grouped by category.

At the top sits **Theme & live preview**: curated presets, a custom colour
picker with contrast/WCAG checking, and a live sample of the tenant's app
(dashboard, reservation, POS, invoice, mobile) rendered in the colours you're
trying. Nothing is written until you press save, and leaving the tab restores
master control's own neutral palette.

Settings used to be editable inside each tenant's app; they were moved here on
purpose. Tenant staff can still *read* their settings (the app needs them to
render), but only a platform operator can change them. A value you haven't
overridden falls back to the system default, and overridden values are marked
as such.

---

## 6. Impersonation

**Tenant → Impersonate admin** opens that tenant's app signed in as their Full
Administrator, without any password being exchanged.

How it works, and why it's safe to leave enabled:

- Clicking mints a **single-use** token that expires in **90 seconds**.
- Only a SHA-256 hash of it is stored, so a database dump or leaked log can't
  be replayed.
- The token is **bound to one tenant** and is consumed only on that tenant's
  own URL prefix (`/api/impersonate/{token}` with the tenant's
  `X-Tenant-Slug`) — it cannot be replayed against another.
- Every impersonation is written to the audit log with the operator's email,
  the target user, and the tenant.

You are then a normal Full Administrator of that tenant, so **anything you do
is recorded as that user**. Sign out when finished. If the link expires before
you click it, just press the button again.

---

## 7. Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| **Master control renders but every call 404s** | You're on a tenant prefix reached without its slug, or the panel is being hit FROM a tenant-slot request (a header is present). Master control only exists at `/{TENANCY_CENTRAL_PREFIX}` with **no** `X-Tenant-Slug`. `EnsureCentralContext` rejects central APIs under a tenant context by design. |
| **`{"message":"Unknown host."}` on the panel in dev** | Almost always `changeOrigin: true` on the Vite proxy rewriting the Host — it must be `false` (central requests resolve from the Host; tenant requests carry the header). |
| Tenant prefix returns 404 | No tenant with that slug, or the tenant is **suspended**. Check the status in master control. |
| *"This site isn't available."* on a path that should work | `/api/host-context` said this path owns nothing — check the slug/status. |
| `hms.com/tenants` (or any tenant-looking root path) does nothing | That path belongs to *a tenant named "tenants"*, which is reserved (see `ReservedSlug`) or none — there is no implicit routing from bare paths to tenants. Only `/{actual slug}` works. |
| Signed out immediately after an impersonation hand-off | Expected: the tenant prefix you landed on doesn't match the session (e.g. the operator's own account). The cross-tenant guard logs the tenant identity out — sign back in on that prefix. |
| Login succeeds then the next call 401s | The origin isn't in `SANCTUM_STATEFUL_DOMAINS` — it needs the bare SPA host entry. |
| A tenant can't see a feature they have permission for | Its module isn't licensed. Check the Modules tab — licensing outranks permissions. |
| Can't sign into master control on a fresh deploy | No operator exists; the demo seeder skips production. Run `php artisan central:create-admin`. |

---

## 8. Cutover (subdomain → prefix tenancy)

The migration ships dual-mode: `IdentifyTenant` resolves the
`X-Tenant-Slug` header first and the **Host fallback** (old
`{slug}.{base}` / `admin.{base}` style) second. Both URL styles work
simultaneously, so the deploy order is: code → frontend → verify → remove.

Cutover steps, in order:

1. Deploy the new backend and SPA build. Old URLs keep working via the host
   fallback; new URL style (`/{slug}/…`, `/admin`) works immediately.
2. **Add the 301** (already generated into the release by
   `release:build`'s `.htaccess` if `TENANCY_BASE_DOMAIN` is set): every
   `{slug}.{base}` **page load** is redirected to `{base}/{slug}/*`. API
   paths (`/api`, `/sanctum`, `/broadcasting`) are deliberately *not*
   redirected — old SPA builds still call `{slug}.{base}/api/…` and the host
   fallback resolves them. For nginx, the sample block is commented in
   `web/nginx.conf`.
3. **Verify** (§7 checklist, applied while both styles are live):
   - Log into two tenants in one browser: the second logs the first out —
     logout, never a data leak.
   - Impersonate from `/admin`, then return to `/admin`: the platform
     operator's session survives (the cross-tenant guard now logs only the
     tenant identity out, never the shared session).
   - Load a printed QR URL (`{slug}.{base}/order/{token}`) → lands on
     `{base}/{slug}/order/{token}`, still works.
   - Deep-link `{base}/{slug}/reservations/5` cold (no previous tenant visit):
     resolves and renders.
   - `{base}/tenants` 404s rather than resolving anything (no path→tenant
     prefix inference — slashes stay reserved).
   - A suspended tenant still returns the indistinguishable 404 at
     `/api/host-context`.
   - Check access logs: confirm no page loads are still arriving by
     subdomain.
4. **Remove** (one commit, once nothing arrives by subdomain):
   - `TENANCY_BASE_DOMAIN` and the `*.{host}` `SANCTUM_STATEFUL_DOMAINS`
     wildcard entry from `backend/.env` (+ the wildcard patterns emitted by
     `config/cors.php` / `config/sanctum.php` alongside it).
   - The Host fallback: `TenantHostResolver::resolve()` /
     `resolveRequest()`'s fallback branch; `IdentifyTenant` /
     `HostContextController` stop consulting the Host.
   - The 301 block in the release's `.htaccess` and the wildcard DNS/TLS
     record.

Two tenants in one browser share one cookie jar from here on; the server-side
session check is the entire isolation boundary (it fails closed — 401).

---

## Reference

| Thing | Where |
| --- | --- |
| Tenancy config | `backend/config/tenancy.php` |
| Slug/header → tenant resolution | `backend/app/Services/TenantHostResolver.php` (+ `backend/app/Http/Middleware/IdentifyTenant.php`) |
| Central-only guard | `backend/app/Http/Middleware/EnsureCentralContext.php` |
| SPA boot gate (host-context) | `backend/app/Http/Controllers/HostContextController.php` · `web/src/main.tsx` |
| SPA tenant-prefix mirror | `web/src/lib/tenancy.ts` |
| Unavailable page | `web/public/unavailable.html` |
| Reserved prefixes | `backend/app/Rules/ReservedSlug.php` |
| Module catalog | `backend/app/Support/ModuleCatalog.php` |
| Module enforcement | `backend/app/Services/TenantModules.php` |
| Tenant provisioning | `backend/app/Services/TenantProvisioning.php` |
| Impersonation | `backend/app/Services/Impersonation.php` |
| Subdomain 301 during cutover | generated into the release `.htaccess` (`release:build`), sample in `web/nginx.conf` |
