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

Point a wildcard record at the server and issue a wildcard certificate:

```
*.vellixglobal.com    A     <server-ip>
vellixglobal.com      A     <server-ip>
```

Every tenant gets a subdomain off this one record — no DNS change is needed
when you add a tenant.

### Backend environment

In `backend/.env`:

```dotenv
APP_URL=https://vellixglobal.com

TENANCY_CENTRAL_SUBDOMAIN=admin

SANCTUM_STATEFUL_DOMAINS=*.vellixglobal.com,vellixglobal.com
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

Resolution is **relative** — the first label of each request's Host header is
the tenant slug (or the central subdomain), everything after it is the base —
so nothing per-domain is configured or baked into builds, and one wildcard
record serves every current and future tenant.

Optional hardening, both off by default:

- `TENANCY_BASE_DOMAIN` — pin to the fixed base domain for **strict mode**:
  hosts not ending in it are rejected outright instead of resolved relatively.
  `php artisan release:build` validates a pin against `APP_URL`.
- `TENANCY_DEV_FALLBACK=true` — dev only: lets a central host serve a tenant
  when a slug is forced via `?tenant=` / `X-Tenant-Slug`. Keep it off in
  production, where hosts resolve strictly from the Host header.

> **Leave `SESSION_DOMAIN` unset.** Setting it to a shared parent
> (`.vellixglobal.com`) makes one session cookie valid on *every* tenant
> subdomain, so a user signed into one tenant carries that session onto
> another's host. `IdentifyTenant` catches the mismatch and forces a logout,
> but the correct posture is per-host cookies — which is what unset gives you.

### Frontend environment

The SPA needs **no tenancy environment** — one build serves every host. At
runtime the bundle asks the backend which shell this host is
(`/api/host-context`, resolved from the Host header) before mounting anything,
and for a tenant the same payload IS its branding (name, logo, theme), so the
login screen renders with no second round-trip (see `web/src/lib/branding.tsx`).

```bash
cd web && npm run build
```

Serve the same `dist/` for every host, with `/api` and `/sanctum` passed
through to Laravel on that same origin, and every other request gated by the
host check in `web/nginx.conf` (see the note at the top of this document).
Master control is served **only** on the central host — a tenant subdomain can
never render it.

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

Local dev uses **real subdomains**, exactly like production. Browsers resolve
every `*.localhost` name to 127.0.0.1 with no hosts-file entry, so no DNS setup
is needed:

| | URL |
| --- | --- |
| Master control | http://admin.localhost:5173 |
| A tenant's app | http://{slug}.localhost:5173 |

This relies on three things, all already configured:

1. `TENANCY_CENTRAL_SUBDOMAIN=admin` in `backend/.env` (no base domain needed —
   resolution is relative).
2. `SANCTUM_STATEFUL_DOMAINS` including `*.localhost:5173`, or login succeeds
   and every call afterwards 401s.
3. **`changeOrigin: false`** on the Vite proxy (`web/vite.config.ts`).
   `changeOrigin: true` rewrites the Host header to the proxy target, and the
   Host is exactly how `IdentifyTenant` decides which tenant a request belongs
   to — with it on, every tenant subdomain reaches Laravel as `127.0.0.1` and
   resolves nothing, so the whole app answers `{"message":"Unknown host."}`.

> If you'd rather not use subdomains at all, set `TENANCY_DEV_FALLBACK=true`
> and stay on one host: master control on `http://localhost:5173` (the bare
> base counts as central), and a tenant app on the same host with an explicit
> `?tenant={slug}` (or an `X-Tenant-Slug` header). That fallback is strictly
> dev-only — in production every host resolves purely from its own Host
> header.

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
  own subdomain — it cannot be replayed against another.
- Every impersonation is written to the audit log with the operator's email,
  the target user, and the tenant.

You are then a normal Full Administrator of that tenant, so **anything you do
is recorded as that user**. Sign out when finished. If the link expires before
you click it, just press the button again.

---

## 7. Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| **`{"message":"Unknown host."}` on every page** | The Host reaching Laravel doesn't match any tenant. Locally this is almost always `changeOrigin: true` on the Vite proxy rewriting it — it must be `false`. Otherwise the host's first label names no tenant (or the tenant is suspended) — check the slug in master control. |
| Tenant subdomain returns 404 | No tenant with that slug, or the tenant is **suspended**. Check the status in master control. |
| *"This site isn't available."* on a host that should work | nginx's host gate (`/_host_check` → `/api/host-context`) got a non-2xx and served `unavailable.html` instead of the app. The host owns nothing from the backend's view — check the slug/status, or that you're on `admin.` for the panel. |
| Master control renders but every call 404s | You're on a tenant subdomain. `EnsureCentralContext` rejects central APIs there by design — use the `admin.` host. |
| Signed out immediately on a tenant subdomain | A session for a *different* tenant was replayed against this host. Expected: `IdentifyTenant` invalidates it. Sign in again. |
| Login succeeds then the next call 401s | The host isn't in `SANCTUM_STATEFUL_DOMAINS`. It needs the wildcard `*.vellixglobal.com`. |
| A tenant can't see a feature they have permission for | Its module isn't licensed. Check the Modules tab — licensing outranks permissions. |
| Can't sign into master control on a fresh deploy | No operator exists; the demo seeder skips production. Run `php artisan central:create-admin`. |

---

## Reference

| Thing | Where |
| --- | --- |
| Tenancy config | `backend/config/tenancy.php` |
| Host → tenant resolution | `backend/app/Services/TenantHostResolver.php` (+ `backend/app/Http/Middleware/IdentifyTenant.php`) |
| Central-only guard | `backend/app/Http/Middleware/EnsureCentralContext.php` |
| SPA boot gate (host-context) | `backend/app/Http/Controllers/HostContextController.php` · `web/src/main.tsx` · `web/nginx.conf` |
| Unavailable page | `web/public/unavailable.html` |
| Reserved subdomains | `backend/app/Rules/ReservedSlug.php` |
| Module catalog | `backend/app/Support/ModuleCatalog.php` |
| Module enforcement | `backend/app/Services/TenantModules.php` |
| Tenant provisioning | `backend/app/Services/TenantProvisioning.php` |
| Impersonation | `backend/app/Services/Impersonation.php` |
| SPA shell selection | `web/src/lib/tenancy.ts` |
