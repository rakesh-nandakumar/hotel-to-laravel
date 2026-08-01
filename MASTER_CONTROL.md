# Master Control — User Guide

Master control is the platform-operator panel that owns every tenant on the
system: it creates them, decides which modules each one has licensed, edits
their settings, and lets you log into any of them without a password.

One codebase serves two different apps, chosen by hostname:

| Host | What loads | Who signs in |
| --- | --- | --- |
| `admin.vellixglobal.com` | Master control | Platform operator (`central_admins`) |
| `{slug}.vellixglobal.com` | That tenant's hotel/apartment/restaurant app | That tenant's staff (`users`) |

These are separate login systems. A platform operator is **not** a user of any
tenant, holds no permissions inside one, and is never subject to tenant
scoping. A tenant's staff can never reach master control — requesting
`/api/central/*` from a tenant subdomain returns 404 even with a valid
operator session.

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

TENANCY_BASE_DOMAIN=vellixglobal.com
TENANCY_CENTRAL_SUBDOMAIN=admin

SANCTUM_STATEFUL_DOMAINS=*.vellixglobal.com,vellixglobal.com
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

> **Leave `SESSION_DOMAIN` unset.** Setting it to a shared parent
> (`.vellixglobal.com`) makes one session cookie valid on *every* tenant
> subdomain, so a user signed into one tenant carries that session onto
> another's host. `IdentifyTenant` catches the mismatch and forces a logout,
> but the correct posture is per-host cookies — which is what unset gives you.

### Frontend environment

In `web/.env`, then rebuild:

```dotenv
VITE_TENANCY_BASE_DOMAIN=vellixglobal.com
VITE_TENANCY_CENTRAL_SUBDOMAIN=admin
```

```bash
cd web && npm run build
```

Serve the same `dist/` for every host, with `/api` and `/sanctum` passed
through to Laravel on that same origin. The bundle picks its own app from
`window.location.hostname`.

Once `VITE_TENANCY_BASE_DOMAIN` is set, master control is served **only** on
the central host. A tenant subdomain hitting `/central` gets the tenant app.

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

Localhost has no subdomains, so master control stays on a path:

| | URL |
| --- | --- |
| Master control | http://localhost:5173/central/login |
| Tenant app | http://localhost:5173/login |

Seed the demo data (`php artisan migrate:fresh --seed`) and sign in with:

- **Platform operator** — `platform@vellix.com` / `password`
- **Tenant staff** — `admin@vellix.com` / `password`

Both fallbacks are development-only. With `APP_ENV=production` the path
shortcut is off and only the real central host works.

---

## 3. Creating a tenant

**Tenants → New tenant.** You provide:

| Field | Notes |
| --- | --- |
| Business name | Display name, e.g. "Acme Hotels" |
| Subdomain | Lowercase letters, numbers and dashes. Becomes `{slug}.vellixglobal.com`. `admin` is reserved and rejected |
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

| Module | Covers |
| --- | --- |
| Hotel Operations | Rooms, reservations, guests, housekeeping, maintenance, laundry, venues, front-desk reports |
| Restaurant / POS | Menu, ordering, dining tables, QR ordering, restaurant reports |
| Apartments | Properties, units, leases, sales, tenant billing, reports |
| Payroll | Pay runs, deductions, payslips |
| Till & Cash Management | Cash drawer sessions, cash in/out, reconciliation |

Toggling one takes effect on the tenant's next request. A disabled module
disappears from their menu and its endpoints return 403.

**Module licensing outranks tenant permissions.** A tenant's own Full
Administrator cannot grant access to a module you haven't licensed — the check
runs before any permission check, and disabled modules are filtered out of
their role-permission matrix entirely. This is what makes modules a commercial
control rather than a suggestion.

Some things are **core** and can never be gated: dashboard, user management,
roles, audit logs, staff and account pages. Otherwise you could lock a tenant
out of their own login and user administration.

---

## 5. Settings

**Tenant → Settings** is where a tenant's configuration lives — currency, tax
rates, branding, document numbering and so on, grouped by category.

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
| Tenant subdomain returns 404 | No tenant with that slug, or the tenant is **suspended**. Check the status in master control. |
| `admin.` shows the tenant app instead of master control | `VITE_TENANCY_BASE_DOMAIN` wasn't set at build time, or `dist/` wasn't rebuilt after setting it. |
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
| Host → tenant resolution | `backend/app/Http/Middleware/IdentifyTenant.php` |
| Central-only guard | `backend/app/Http/Middleware/EnsureCentralContext.php` |
| Module catalog | `backend/app/Support/ModuleCatalog.php` |
| Module enforcement | `backend/app/Services/TenantModules.php` |
| Tenant provisioning | `backend/app/Services/TenantProvisioning.php` |
| Impersonation | `backend/app/Services/Impersonation.php` |
| SPA app selection | `web/src/lib/tenancy.ts` |
