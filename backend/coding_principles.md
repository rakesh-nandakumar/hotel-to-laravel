# Laravel Enterprise Design Principles & Architecture Standards

## 1. Technology Stack

### 1.1 Core Stack

| Component | Technology |
|---|---|
| Backend Framework | Laravel 12 |
| Frontend Framework | React |
| Build Tool | Vite |
| Database | MySQL |
| API Layer | Laravel API / Controllers / Resources |
| Authentication | Laravel Auth / Sanctum / JWT (based on project requirements) |

---

### 1.2 Frontend Philosophy

The system uses a **React + Vite frontend** with Laravel as the backend.

Core rules:

- React handles UI rendering and client interactions
- Laravel handles business logic and domain rules
- Backend remains the single source of truth
- API-first communication between React and Laravel
- No business logic inside React components

---

### 1.3 UI Architecture Standards

Frontend should follow:

- Component-driven design
- Reusable form components
- Reusable table components
- Centralized state management (if needed)
- API service abstraction layer
- Role-aware UI rendering

Avoid:

- Duplicate components
- Business logic inside views
- Massive page components
- Direct API calls scattered across components

---

### 1.4 Data Table Standards

All data tables must support:

- Server-side pagination
- Server-side sorting
- Server-side searching
- Column-wise filtering
- Bulk actions
- Export support where relevant
- Persistent filter state

Large datasets must never rely on client-side filtering.

---

### 1.5 Third-Party Integrations

| Service | Usage |
|---|---|
| Stripe | Payment processing |
| WooCommerce | E-commerce sync |
| QuickBooks | Accounting sync |
| Cloudflare Beacon | Analytics / Web Vitals |

---

### 1.6 Supported Languages

English, French, Arabic, Turkish, Simplified Chinese, Thai, Hindi, German, Spanish, Italian, Indonesian, Traditional Chinese, Russian, Vietnamese, Korean, Bangla, Portuguese.

RTL support must be available.

---

### 1.7 Theme Support

The system should support:

- Light mode
- Dark mode

Theme selection should persist per user.

---

# Design Principles

## 2. Enums vs Database Tables

### Problem with Enums

Using enums for business-driven values becomes limiting as systems grow.

Problems:

- Cannot be changed without deployments
- Not configurable
- Difficult to extend
- Poor for multi-tenant systems
- Creates migration dependency

### When Enums Are Acceptable

Use enums only when values are genuinely static:

- Yes / No
- Internal constants
- Fixed system values
- Non-business states

---

### Better Approach: Lookup Tables

Business-facing values should use tables.

Example:

```php
Schema::create('statuses', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('name');
    $table->string('color')->nullable();
    $table->integer('sort_order')->default(0);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

Usage:

```php
$order->status->code;
$order->status->name;
```

### Core Takeaway

- Enums → static values
- Tables → configurable values

---

## 3. Never Depend on Raw IDs (Stable Keys)

### Problem

Hardcoded IDs are fragile.

Bad:

```php
if ($order->status_id == 1)
```

Issues:

- Environment mismatch
- Seed order differences
- Migration/import issues
- Tenant inconsistency

---

### Correct Approach

Use immutable codes.

Example:

```php
$table->string('code')->unique();
```

Logic:

```php
if ($order->status->code === 'approved')
```

Optional constants:

```php
class Status
{
    public const APPROVED = 'approved';
}
```

Usage:

```php
if ($order->status->code === Status::APPROVED)
```

### Core Rule

IDs are database internals.

Codes are business identifiers.

---

## 4. Auditability

### Principle

Every important record must answer:

- Who created it?
- Who modified it?
- Who deleted it?
- When?

Without this, systems become untraceable.

---

### Required Fields

```php
$table->foreignId('created_by')->nullable()->constrained('users');
$table->foreignId('updated_by')->nullable()->constrained('users');
$table->foreignId('deleted_by')->nullable()->constrained('users');

$table->timestamps();
$table->softDeletes();
```

---

### Model Trait

```php
trait Auditable
{
    protected static function bootAuditable()
    {
        static::creating(function ($model) {
            $model->created_by = auth()->id();
        });

        static::updating(function ($model) {
            $model->updated_by = auth()->id();
        });

        static::deleting(function ($model) {
            if (
                method_exists($model, 'isForceDeleting') &&
                ! $model->isForceDeleting()
            ) {
                $model->deleted_by = auth()->id();
                $model->save();
            }
        });
    }
}
```

Usage:

```php
class Order extends Model
{
    use SoftDeletes, Auditable;
}
```

---

### Core Rule

Auditability is mandatory.

If you cannot explain record history, the system is incomplete.

---

## 5. System Logging

Audit fields are not enough.

Logs capture:

- failures
- denied access
- background jobs
- validation failures
- system activity

---

### Two Layers

### Model-Level Logging

Observer example:

```php
class OrderObserver
{
    public function updated($order)
    {
        logger()->info('Order updated', [
            'order_id' => $order->id,
            'changes' => $order->getChanges(),
            'user_id' => auth()->id()
        ]);
    }
}
```

Register:

```php
Order::observe(OrderObserver::class);
```

---

### Controller-Level Logging

```php
logger()->info('Order creation attempt', [
    'payload' => $request->all(),
    'user_id' => auth()->id()
]);
```

---

### Required Logging Areas

- CRUD
- Login/logout
- Permission denials
- Payments
- Jobs
- Imports/exports
- Integrations

---

### Core Rule

If an event timeline cannot be reconstructed, logging is insufficient.

---

## 6. Relational UX (Connect or Create)

### Problem

Systems often force navigation between modules to manage relationships.

This destroys workflow.

---

### Principle

Every relationship field should support:

- Search existing
- Select existing
- Create new inline
- Immediate attach

No context switching.

---

### UX Flow

1. User types
2. Suggestions appear
3. Select existing OR create new
4. Relation attaches immediately

### Core Rule

Relationships belong inside the workflow.

Leaving the page to create relations is poor UX.

---

## 7. Column-Wise Filtering

### Principle

Every data table must support column filtering.

Global search alone is insufficient.

---

### Requirements

- Column filtering
- Combined filters
- Persistent filters
- Fast queries
- Indexed database search

### Core Rule

Users must be able to narrow large datasets quickly.

Filtering is not optional.

---

# Final Architecture Mindset

Core philosophy:

- Laravel owns business logic
- React owns UI
- Tables over enums
- Codes over IDs
- Auditability mandatory
- Logs mandatory
- Relationships inline
- Filtering everywhere
- Performance by design
- UX without unnecessary navigation

Enterprise systems are not built around pages.

They are built around workflows, traceability, and scalability.