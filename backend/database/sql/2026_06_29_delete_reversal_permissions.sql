-- Delete-reversal permissions, menu_items.actions, movement_types, and role grants.
-- Production-safe: additive only. Every statement is idempotent.
-- Run ONCE on the production MySQL database after deploying the code changes.
-- DO NOT run PermissionsAndRolesSeeder or MenuSeeder on production — use this script.

START TRANSACTION;

-- ─── 1. movement_types ────────────────────────────────────────────────────────
-- grn_reversal was added in migration 2026_06_25 — skip it here.
-- The five new reversal types match the migration 2026_06_29_000001.

INSERT INTO movement_types (code, name, direction, sort_order, is_active, created_at, updated_at)
SELECT 'invoice_reversal', 'Invoice Reversal (Admin Delete)', 'in', 100, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM movement_types WHERE code = 'invoice_reversal');

INSERT INTO movement_types (code, name, direction, sort_order, is_active, created_at, updated_at)
SELECT 'return_reversal', 'Return Reversal (Admin Delete)', 'out', 101, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM movement_types WHERE code = 'return_reversal');

INSERT INTO movement_types (code, name, direction, sort_order, is_active, created_at, updated_at)
SELECT 'adjustment_reversal', 'Adjustment Reversal (Admin Delete)', 'out', 102, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM movement_types WHERE code = 'adjustment_reversal');

INSERT INTO movement_types (code, name, direction, sort_order, is_active, created_at, updated_at)
SELECT 'transfer_reversal', 'Transfer Reversal (Admin Delete)', 'in', 103, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM movement_types WHERE code = 'transfer_reversal');

INSERT INTO movement_types (code, name, direction, sort_order, is_active, created_at, updated_at)
SELECT 'damage_reversal', 'Damage Reversal (Admin Delete)', 'in', 104, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM movement_types WHERE code = 'damage_reversal');

-- ─── 2. permissions ───────────────────────────────────────────────────────────

INSERT INTO permissions (name, created_at, updated_at)
SELECT 'isms_invoices.delete', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'isms_invoices.delete');

INSERT INTO permissions (name, created_at, updated_at)
SELECT 'isms_grn.delete', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'isms_grn.delete');

INSERT INTO permissions (name, created_at, updated_at)
SELECT 'isms_returns.delete', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'isms_returns.delete');

INSERT INTO permissions (name, created_at, updated_at)
SELECT 'isms_stock_adjustment.delete', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'isms_stock_adjustment.delete');

INSERT INTO permissions (name, created_at, updated_at)
SELECT 'isms_stock_transfer.delete', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'isms_stock_transfer.delete');

INSERT INTO permissions (name, created_at, updated_at)
SELECT 'isms_stock_damages.delete', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'isms_stock_damages.delete');

INSERT INTO permissions (name, created_at, updated_at)
SELECT 'isms_transactions.delete', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'isms_transactions.delete');

-- ─── 3. menu_items.actions — JSON-append "delete" (idempotent via JSON_CONTAINS) ─

UPDATE menu_items
SET actions = JSON_ARRAY_APPEND(actions, '$', 'delete'),
    updated_at = NOW()
WHERE module_key = 'isms_invoices'
  AND NOT JSON_CONTAINS(actions, '"delete"', '$');

UPDATE menu_items
SET actions = JSON_ARRAY_APPEND(actions, '$', 'delete'),
    updated_at = NOW()
WHERE module_key = 'isms_grn'
  AND NOT JSON_CONTAINS(actions, '"delete"', '$');

UPDATE menu_items
SET actions = JSON_ARRAY_APPEND(actions, '$', 'delete'),
    updated_at = NOW()
WHERE module_key = 'isms_returns'
  AND NOT JSON_CONTAINS(actions, '"delete"', '$');

UPDATE menu_items
SET actions = JSON_ARRAY_APPEND(actions, '$', 'delete'),
    updated_at = NOW()
WHERE module_key = 'isms_stock_adjustment'
  AND NOT JSON_CONTAINS(actions, '"delete"', '$');

UPDATE menu_items
SET actions = JSON_ARRAY_APPEND(actions, '$', 'delete'),
    updated_at = NOW()
WHERE module_key = 'isms_stock_transfer'
  AND NOT JSON_CONTAINS(actions, '"delete"', '$');

UPDATE menu_items
SET actions = JSON_ARRAY_APPEND(actions, '$', 'delete'),
    updated_at = NOW()
WHERE module_key = 'isms_stock_damages'
  AND NOT JSON_CONTAINS(actions, '"delete"', '$');

UPDATE menu_items
SET actions = JSON_ARRAY_APPEND(actions, '$', 'delete'),
    updated_at = NOW()
WHERE module_key = 'isms_transactions'
  AND NOT JSON_CONTAINS(actions, '"delete"', '$');

-- ─── 4. role_permissions — grant all seven .delete to Full Administrator ──────
-- Full Administrator already bypasses permission checks (is_full_admin=true),
-- but we keep role_permissions consistent with the 'all' model in the seeder.

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.name IN (
    'isms_invoices.delete',
    'isms_grn.delete',
    'isms_returns.delete',
    'isms_stock_adjustment.delete',
    'isms_stock_transfer.delete',
    'isms_stock_damages.delete',
    'isms_transactions.delete'
)
WHERE r.name = 'Full Administrator'
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions rp2
      WHERE rp2.role_id = r.id AND rp2.permission_id = p.id
  );

COMMIT;
