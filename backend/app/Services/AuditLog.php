<?php

namespace App\Services;

use App\Models\AuditLog as AuditLogModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        array $context = [],
        ?int $actorId = null,
        ?string $description = null,
    ): AuditLogModel {
        $resolvedActor = $actorId ?? Auth::id();

        $actorName = null;
        if ($resolvedActor) {
            $actorName = User::query()->find($resolvedActor)?->name;
        }

        $subjectType = $subject ? $subject::class : null;
        $subjectId = $subject?->getKey();

        $computedDescription = $description ?? self::describeForAction($action, $actorName ?? 'System', $subjectType, $subjectId, $context);

        return AuditLogModel::create([
            'actor_id' => $resolvedActor,
            'action' => $action,
            'description' => $computedDescription,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'context' => $context,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 255) ?: null,
            'route' => Request::method().' '.Request::path(),
            'created_at' => now(),
        ]);
    }

    public static function describe(AuditLogModel $log): string
    {
        if (! empty($log->description)) {
            return rtrim(trim((string) $log->description), '.').'.';
        }

        $ctx = $log->context ?? [];
        $actor = $log->actor?->name ?? 'System';

        return self::describeForAction($log->action, $actor, $log->subject_type, $log->subject_id, $ctx);
    }

    /**
     * Centralized description generation used both when recording and when rendering.
     *
     * @param  mixed  $subjectId
     * @param  array<string,mixed>  $ctx
     */
    private static function describeForAction(string $action, string $actor, ?string $subjectType, $subjectId, array $ctx): string
    {
        $subject = $subjectType ? class_basename($subjectType).'#'.($subjectId ?? '?') : null;

        // Build a human-readable "changed X from A to B" summary when before/after pairs are present.
        $changes = '';
        if (! empty($ctx['changed'])) {
            $changes = ' Changed: '.implode(', ', (array) $ctx['changed']).'.';
        }

        $description = match ($action) {
            // ── Auth ──────────────────────────────────────────────────────────
            'user.login' => "{$actor} signed in successfully.",
            'user.logout' => "{$actor} signed out.",
            'user.login_failed' => 'Failed login attempt for email "'.($ctx['email'] ?? 'unknown').'". '
                .(! empty($ctx['reason']) ? 'Reason: '.$ctx['reason'].'. ' : '')
                .'Attempt '.($ctx['attempts'] ?? '?').' of '.($ctx['max_attempts'] ?? '?').'.',
            'user.locked' => 'Account for "'.($ctx['email'] ?? $subject ?? 'unknown').'" has been locked after '
                .($ctx['attempts'] ?? '?').' consecutive failed login attempts.',
            'user.unlocked' => "{$actor} manually unlocked the account for ".($ctx['target'] ?? $subject ?? 'unknown').'.',
            'user.two_factor_challenged' => 'A second factor was requested at login for '.($subject ?? 'unknown')
                .(! empty($ctx['method']) ? ' via '.str_replace('_', ' ', (string) $ctx['method']) : '').'.',
            'user.login_otp_sent' => 'A login verification code was emailed to '.($subject ?? 'unknown').'.',
            'user.login_otp_failed' => 'A login verification code failed for '.($subject ?? 'unknown')
                .(! empty($ctx['via']) ? ' (via '.str_replace('_', ' ', (string) $ctx['via']).')' : '').'.',
            'user.two_factor_failed' => 'A two-factor challenge failed for '.($subject ?? 'unknown').'.',
            'user.two_factor_enabled' => "{$actor} started enabling two-factor authentication.",
            'user.two_factor_confirmed' => "{$actor} confirmed two-factor authentication with an authenticator app.",
            'user.two_factor_disabled' => "{$actor} disabled authenticator-app two-factor authentication.",
            'user.two_factor_email_enabled' => "{$actor} enabled email verification codes at login.",
            'user.two_factor_email_disabled' => "{$actor} disabled email verification codes at login.",
            'user.recovery_code_used' => 'A recovery code was used to complete a login for '.($subject ?? 'unknown').'.',
            'user.recovery_codes_regenerated' => "{$actor} generated a new set of recovery codes.",
            'user.password_reset_requested' => 'A password reset code was requested for '.($subject ?? 'unknown').'.',
            'user.password_reset_completed' => 'The password for '.($subject ?? 'unknown').' was reset via emailed code.',
            'user.password_reset_by_admin' => "{$actor} set a new password for ".($subject ?? 'unknown')
                .' — the user must change it at next login.',

            // ── Users ─────────────────────────────────────────────────────────
            'user.created' => "{$actor} created a new user account: ".($ctx['name'] ?? $subject ?? 'unknown')
                .(! empty($ctx['email']) ? ' ('.$ctx['email'].')' : '')
                .(! empty($ctx['role']) ? ', assigned role "'.$ctx['role'].'"' : '').'.',
            'user.updated' => "{$actor} updated user ".($ctx['name'] ?? $subject ?? 'unknown')
                .(! empty($ctx['email']) ? ' ('.$ctx['email'].')' : '').'.'.$changes,
            'user.deleted' => "{$actor} permanently deleted user account for ".($ctx['name'] ?? $subject ?? 'unknown')
                .(! empty($ctx['email']) ? ' ('.$ctx['email'].')' : '').'.',
            'user.password_reset' => "{$actor} reset the password for ".($ctx['target'] ?? $subject ?? 'unknown').'.',
            'user.suspended' => "{$actor} suspended ".($ctx['target'] ?? $subject ?? 'unknown')
                .(! empty($ctx['reason']) ? '. Reason: '.$ctx['reason'] : '').'. The user can no longer sign in.',
            'user.reactivated' => "{$actor} reactivated ".($ctx['target'] ?? $subject ?? 'unknown').'. The account is now active.',
            'user.deactivated' => "{$actor} deactivated ".($ctx['target'] ?? $subject ?? 'unknown').'. The account has been marked inactive.',

            // ── Roles ─────────────────────────────────────────────────────────
            'role.created' => "{$actor} created a new role: \""
                .($ctx['name'] ?? $subject ?? 'unknown').'\"'
                .(! empty($ctx['permissions_count']) ? ' with '.$ctx['permissions_count'].' permission(s)' : '').'. ',
            'role.updated' => (function () use ($actor, $ctx, $subject): string {
                $name = $ctx['name'] ?? $subject ?? 'unknown';
                $parts = [];
                if (! empty($ctx['added'])) {
                    $parts[] = 'added: '.implode(', ', (array) $ctx['added']);
                }
                if (! empty($ctx['removed'])) {
                    $parts[] = 'removed: '.implode(', ', (array) $ctx['removed']);
                }
                $detail = $parts ? ' Permissions changed — '.implode('; ', $parts).'.' : '';

                return "{$actor} updated role \"{$name}\".{$detail}";
            })(),
            'role.deleted' => "{$actor} deleted role \""
                .($ctx['name'] ?? $subject ?? 'unknown').'\"'
                .(! empty($ctx['users_affected']) ? ' which had '.$ctx['users_affected'].' assigned user(s)' : '').'.',
            'role.resynced' => "{$actor} re-synced role \""
                .($ctx['name'] ?? $subject ?? 'unknown').'\"'
                .' — permissions propagated to '.($ctx['user_count'] ?? '?').' user(s).',
            'role.duplicated' => "{$actor} duplicated role \""
                .($ctx['source'] ?? $subject ?? 'unknown').'\" into new role "'
                .($ctx['name'] ?? 'unknown').'".',
            'role.toggle_active', 'role.toggled_active' => "{$actor} ".($ctx['is_active'] ? 'activated' : 'deactivated').' role "'
                .($ctx['name'] ?? $subject ?? 'unknown').'".',
            'role.assigned' => "{$actor} assigned role \""
                .($ctx['role'] ?? 'unknown').'\" to '.($ctx['target'] ?? $subject ?? 'unknown').'.',
            'role.revoked' => "{$actor} revoked role \""
                .($ctx['role'] ?? 'unknown').'\" from '.($ctx['target'] ?? $subject ?? 'unknown').'.',

            // ── Security ──────────────────────────────────────────────────────
            'escalation.blocked' => (function () use ($actor, $ctx): string {
                $perms = ! empty($ctx['permissions'])
                    ? implode(', ', (array) $ctx['permissions'])
                    : ($ctx['attempted_permission'] ?? null);

                return "{$actor} attempted to grant permissions they do not hold."
                    .($perms ? " Blocked permission(s): {$perms}." : ' The operation was blocked.');
            })(),
            'permission.direct_grant' => "{$actor} directly granted permissions to "
                .($ctx['target'] ?? $subject ?? 'unknown')
                .(! empty($ctx['permissions']) ? ': '.implode(', ', (array) $ctx['permissions']) : '').'.',
            'permission.direct_revoke' => "{$actor} revoked direct permissions from "
                .($ctx['target'] ?? $subject ?? 'unknown')
                .(! empty($ctx['permissions']) ? ': '.implode(', ', (array) $ctx['permissions']) : '').'.',

            // ── Rooms ─────────────────────────────────────────────────────────
            'room_type.created' => "{$actor} created room type \"".($ctx['name'] ?? $subject ?? 'unknown').'".',
            'room_type.updated' => "{$actor} updated room type \"".($ctx['name'] ?? $subject ?? 'unknown').'".'.$changes,
            'room_type.seasonal_rate_added' => "{$actor} added seasonal rate \""
                .($ctx['name'] ?? 'unknown').'\" ('.($ctx['rate'] ?? '?').') to '.($subject ?? 'a room type').'.',
            'room_type.seasonal_rate_removed' => "{$actor} removed a seasonal rate from ".($subject ?? 'a room type').'.',
            'room.created' => "{$actor} created room \"".($ctx['number'] ?? $subject ?? 'unknown').'".',
            'room.updated' => "{$actor} updated room \"".($ctx['number'] ?? $subject ?? 'unknown').'".'.$changes,
            'room.status_changed' => "{$actor} changed room ".($subject ?? 'unknown').' status from '
                .($ctx['from'] ?? '?').' to '.($ctx['to'] ?? '?').'.',
            'package.updated' => "{$actor} updated package \"".($ctx['name'] ?? $subject ?? 'unknown').'".',

            // ── Guests ────────────────────────────────────────────────────────
            'guest.created' => "{$actor} created guest \"".($ctx['name'] ?? $subject ?? 'unknown').'".',
            'guest.updated' => "{$actor} updated guest \"".($ctx['name'] ?? $subject ?? 'unknown').'".'.$changes,
            'guest.loyalty_adjusted' => "{$actor} adjusted loyalty points for ".($subject ?? 'a guest').' by '
                .($ctx['points'] ?? '?').' — reason: '.($ctx['reason'] ?? 'unspecified').'.',

            // ── Corporate Accounts ───────────────────────────────────────────────
            'corporate.created' => "{$actor} created corporate account \"".($ctx['name'] ?? $subject ?? 'unknown').'".',
            'corporate.updated' => "{$actor} updated corporate account \"".($ctx['name'] ?? $subject ?? 'unknown').'".'.$changes,
            'corporate.settled' => "{$actor} recorded a ".($ctx['method'] ?? '?').' settlement of LKR '
                .number_format(($ctx['amount'] ?? 0) / 100, 2).' for '.($ctx['name'] ?? $subject ?? 'a corporate account').'.',

            // ── Reservations & Folios ────────────────────────────────────────────
            'reservation.created' => "{$actor} created reservation \"".($ctx['code'] ?? $subject ?? 'unknown')
                .'\" — stay total LKR '.number_format(($ctx['stay_total'] ?? 0) / 100, 2)
                .', deposit due LKR '.number_format(($ctx['deposit_due'] ?? 0) / 100, 2).'.',
            'reservation.updated' => "{$actor} updated reservation \"".($ctx['code'] ?? $subject ?? 'unknown').'".',
            'reservation.checked_in' => "{$actor} checked in reservation \"".($ctx['code'] ?? $subject ?? 'unknown').'".',
            'reservation.checked_out' => "{$actor} checked out reservation ".($subject ?? 'unknown')
                .' — invoice '.($ctx['invoice_no'] ?? '?').', total LKR '.number_format(($ctx['total'] ?? 0) / 100, 2)
                .(! empty($ctx['loyalty_earned']) ? ', earned '.$ctx['loyalty_earned'].' loyalty point(s)' : '').'.',
            'reservation.cancelled' => "{$actor} cancelled reservation ".($subject ?? 'unknown')
                .' — '.($ctx['refund_pct'] ?? 0).'% refund (LKR '.number_format(($ctx['refunded'] ?? 0) / 100, 2).')'
                .'. Reason: '.($ctx['reason'] ?? 'unspecified').'.',
            'folio.line_added' => "{$actor} added a ".($ctx['source'] ?? 'folio').' charge of LKR '
                .number_format(($ctx['amount'] ?? 0) / 100, 2).' to '.($subject ?? 'a folio').'.',
            'folio.line_voided' => "{$actor} voided a folio line (LKR ".number_format(($ctx['amount'] ?? 0) / 100, 2).') on '
                .($subject ?? 'a folio').'. Reason: '.($ctx['reason'] ?? 'unspecified').'.',
            'payment.recorded' => "{$actor} recorded a ".($ctx['method'] ?? '?').' payment of LKR '
                .number_format(($ctx['amount'] ?? 0) / 100, 2).'.',
            'payment.refunded' => "{$actor} issued a ".($ctx['method'] ?? '?').' refund of LKR '
                .number_format(($ctx['amount'] ?? 0) / 100, 2).'. Reason: '.($ctx['reason'] ?? 'unspecified').'.',

            // ── Restaurant Menu & Inventory ──────────────────────────────────────
            'menu_category.created' => "{$actor} created menu category \"".($ctx['name'] ?? $subject ?? 'unknown').'".',
            'menu_category.updated' => "{$actor} updated menu category \"".($ctx['name'] ?? $subject ?? 'unknown').'".',
            'menu_category.deleted' => "{$actor} removed menu category \"".($ctx['name'] ?? $subject ?? 'unknown').'".',
            'menu_item.created' => "{$actor} created menu item \"".($ctx['name'] ?? $subject ?? 'unknown').'\" (#'.($ctx['item_no'] ?? '?').').',
            'menu_item.updated' => "{$actor} updated menu item \"".($ctx['name'] ?? $subject ?? 'unknown').'".',
            'menu_item.deleted' => "{$actor} removed menu item \"".($ctx['name'] ?? $subject ?? 'unknown').'".',
            'menu_item.archived' => "{$actor} archived menu item \"".($ctx['name'] ?? $subject ?? 'unknown')
                .'" — it appears in '.($ctx['past_orders'] ?? '?').' past order(s), so it was deactivated instead of deleted.',
            'menu_item.sold_out_toggled' => "{$actor} marked \"".($ctx['name'] ?? $subject ?? 'unknown').'" as '
                .(($ctx['sold_out'] ?? false) ? 'sold out' : 'available').'.',
            'ingredient.created' => "{$actor} added ingredient \"".($ctx['name'] ?? $subject ?? 'unknown').'".',
            'ingredient.updated' => "{$actor} updated ingredient \"".($ctx['name'] ?? $subject ?? 'unknown').'".',
            'ingredient.deleted' => "{$actor} removed ingredient \"".($ctx['name'] ?? $subject ?? 'unknown')
                .'\" (stock at deletion: '.($ctx['stock_at_deletion'] ?? 0).').',
            'ingredient.stock_adjusted' => "{$actor} adjusted stock for ".($subject ?? 'an ingredient').' by '
                .($ctx['delta'] ?? '?').' — reason: '.($ctx['reason'] ?? 'unspecified').'.',
            'ingredient.batch_written_off' => "{$actor} wrote off ".($ctx['qty'] ?? '?').' of '
                .($ctx['ingredient'] ?? 'a batch').' — reason: '.($ctx['reason'] ?? 'unspecified').'.',

            // ── POS Orders ────────────────────────────────────────────────────────
            'order.created' => "{$actor} created order #".($ctx['order_no'] ?? $subject ?? 'unknown').' ('.($ctx['type'] ?? '?').').',
            'order_item.voided' => "{$actor} voided \"".($ctx['name'] ?? 'an item').'" on '.($subject ?? 'an order')
                .(($ctx['restocked'] ?? false) ? ' (raw materials restocked)' : '').'. Reason: '.($ctx['reason'] ?? 'unspecified').'.',
            'order.discount_applied' => "{$actor} applied a ".($ctx['mode'] ?? '?').' discount of '.($ctx['value'] ?? '?')
                .' (LKR '.number_format(($ctx['discount'] ?? 0) / 100, 2).') to '.($subject ?? 'an order')
                .'. Reason: '.($ctx['reason'] ?? 'unspecified').'.',
            'order.settled' => "{$actor} settled ".($subject ?? 'an order').' — total LKR '
                .number_format(($ctx['total'] ?? 0) / 100, 2).' via '.implode(', ', (array) ($ctx['methods'] ?? [])).'.',
            'order.charged_to_room' => "{$actor} charged ".($subject ?? 'an order').' to room folio for reservation "'
                .($ctx['reservation'] ?? 'unknown').'".',
            'order.voided' => "{$actor} voided ".($subject ?? 'an order')
                .(($ctx['restocked'] ?? false) ? ' (raw materials restocked)' : '').'. Reason: '.($ctx['reason'] ?? 'unspecified').'.',

            // ── Housekeeping ──────────────────────────────────────────────────────
            'housekeeping.completed' => "{$actor} completed the cleaning checklist for room ".($ctx['room'] ?? $subject ?? 'unknown').'.',

            // ── Maintenance ───────────────────────────────────────────────────────
            'maintenance.logged' => "{$actor} logged a maintenance issue: \"".($ctx['description'] ?? $subject ?? 'unknown').'".',
            'maintenance.updated' => "{$actor} updated ".($subject ?? 'a maintenance issue').' — status: '.($ctx['status'] ?? '?').'.',

            // ── Laundry ───────────────────────────────────────────────────────────
            'laundry_item.created' => "{$actor} added laundry item \"".($ctx['name'] ?? $subject ?? 'unknown').'".',
            'laundry_item.updated' => "{$actor} updated laundry item \"".($ctx['name'] ?? $subject ?? 'unknown').'".',
            'laundry.charged' => "{$actor} charged LKR ".number_format(($ctx['total'] ?? 0) / 100, 2).' of laundry ('
                .($ctx['items'] ?? '?').' item(s)) to '.($subject ?? 'a folio').'.',

            // ── Venues ────────────────────────────────────────────────────────────
            'venue.updated' => "{$actor} updated venue \"".($ctx['name'] ?? $subject ?? 'unknown').'".',
            'venue_booking.created' => "{$actor} created venue booking \"".($ctx['code'] ?? $subject ?? 'unknown')
                .'\" — rental LKR '.number_format(($ctx['rental'] ?? 0) / 100, 2)
                .', deposit due LKR '.number_format(($ctx['deposit_due'] ?? 0) / 100, 2).'.',
            'venue_booking.confirmed' => "{$actor} confirmed venue booking \"".($ctx['code'] ?? $subject ?? 'unknown').'".',
            'venue_booking.completed' => "{$actor} completed ".($subject ?? 'a venue booking').' — invoice '.($ctx['invoice_no'] ?? '?').'.',
            'venue_booking.cancelled' => "{$actor} cancelled ".($subject ?? 'a venue booking').' — refunded LKR '
                .number_format(($ctx['refunded'] ?? 0) / 100, 2).'. Reason: '.($ctx['reason'] ?? 'unspecified').'.',

            // ── Shifts ────────────────────────────────────────────────────────────
            'shift.opened' => "{$actor} opened a shift with LKR ".number_format(($ctx['opening_cash'] ?? 0) / 100, 2).' in the drawer.',
            'shift.closed' => "{$actor} closed ".($subject ?? 'a shift').' — expected LKR '
                .number_format(($ctx['expected_cash'] ?? 0) / 100, 2).', counted LKR '.number_format(($ctx['closing_cash'] ?? 0) / 100, 2)
                .' (variance LKR '.number_format(($ctx['variance'] ?? 0) / 100, 2).').',

            // ── Payroll ───────────────────────────────────────────────────────────
            'payroll.salary_updated' => "{$actor} updated payroll settings for ".($ctx['name'] ?? $subject ?? 'unknown').'.',
            'payroll_run.created' => "{$actor} generated the payroll run for ".($ctx['month'] ?? $subject ?? 'unknown').'.',
            'payroll_run.deleted' => "{$actor} deleted the payroll run for ".($ctx['month'] ?? $subject ?? 'unknown').'.',
            'payroll_run.finalized' => "{$actor} finalized the payroll run for ".($ctx['month'] ?? $subject ?? 'unknown').'.',
            'payroll_line.paid' => "{$actor} marked ".($subject ?? 'a payroll line').' as paid — net LKR '
                .number_format(($ctx['net_pay'] ?? 0) / 100, 2).'.',

            // ── Visitors ──────────────────────────────────────────────────────────
            'visitor.signed_in' => "{$actor} signed in visitor \"".($ctx['name'] ?? $subject ?? 'unknown').'"'
                .(! empty($ctx['vehicle_no']) ? ' (vehicle '.$ctx['vehicle_no'].')' : '').'.',
            'visitor.signed_out' => "{$actor} signed out visitor \"".($ctx['name'] ?? $subject ?? 'unknown').'".',

            // ── Staff ─────────────────────────────────────────────────────────────
            'staff.pin_set' => "{$actor} set a PIN unlock code for ".($subject ?? 'a staff member').'.',
            'staff.pin_cleared' => "{$actor} cleared the PIN unlock code for ".($subject ?? 'a staff member').'.',

            // ── Settings ──────────────────────────────────────────────────────────
            'setting.changed' => "{$actor} changed setting \"".($ctx['key'] ?? 'unknown').'" from '
                .json_encode($ctx['from'] ?? null).' to '.json_encode($ctx['to'] ?? null).'.',

            // ── Reports ───────────────────────────────────────────────────────────
            'night_audit.run' => "{$actor} ran the night audit for ".($ctx['date'] ?? $subject ?? 'unknown').'.',

            // ── Default ───────────────────────────────────────────────────────
            default => ! empty($ctx['description'])
                ? (string) $ctx['description']
                : ucwords(str_replace(['.', '_'], [' - ', ' '], $action)).($subject ? ' on '.$subject : '').'.',
        };

        return rtrim(trim($description), '.').'.';
    }
}
