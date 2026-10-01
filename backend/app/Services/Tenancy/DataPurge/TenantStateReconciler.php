<?php

namespace App\Services\Tenancy\DataPurge;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Deleting records never undoes what they did to rows that are kept: a room
 * stays "occupied" after its stay is gone, a guest keeps the points earned on
 * a deleted invoice. Nothing in the app deletes such records itself (every side
 * effect lives in a service call), so a purge has to repair that state.
 *
 * Only rows the purge actually touched are examined, and only when nothing
 * that would justify their current status remains — a room a receptionist
 * marked occupied by hand, in a purge that never touched it, is left alone.
 *
 * Every change is returned so the backup can record it and a restore can
 * reverse it:
 *   {kind: set,   table, id, column, old, new}   status reset (reversed only if still at `new`)
 *   {kind: delta, table, id, column, amount}     numeric adjustment (reversed by subtracting)
 */
final class TenantStateReconciler
{
    private const CHUNK = 500;

    /** @var array<string, int|null> */
    private array $lookupCache = [];

    /**
     * Must run BEFORE the rows are deleted: it reads what they reference.
     *
     * @return array{rooms: list<int>, dining_tables: list<int>, apartment_units: list<int>, guests: list<int>, loyalty: array<int, int>}
     */
    public function capture(PurgePlan $plan): array
    {
        $ids = $plan->ids;

        $touched = [
            'rooms' => $this->referenced($ids, [
                ['reservation_rooms', 'room_id'], ['housekeeping_tasks', 'room_id'],
                ['maintenance_issues', 'room_id'], ['room_item_checks', 'room_id'],
            ]),
            'dining_tables' => $this->referenced($ids, [['orders', 'dining_table_id']]),
            'apartment_units' => $this->referenced($ids, [
                ['apartment_bookings', 'unit_id'], ['apartment_leases', 'unit_id'], ['apartment_sales', 'unit_id'],
                ['apartment_housekeeping_tasks', 'unit_id'], ['apartment_maintenance_issues', 'unit_id'],
            ]),
            'guests' => $this->referenced($ids, [
                ['reservations', 'guest_id'], ['venue_bookings', 'guest_id'], ['loyalty_transactions', 'guest_id'],
            ]),
        ];

        // Entities that are themselves being deleted need no repair.
        foreach ($touched as $table => $list) {
            $gone = $ids[$table] ?? [];
            $touched[$table] = $gone === [] ? $list : array_values(array_diff($list, $gone));
        }

        $loyalty = [];
        foreach (array_chunk($ids['loyalty_transactions'] ?? [], self::CHUNK) as $chunk) {
            $sums = DB::table('loyalty_transactions')
                ->whereIn('id', $chunk)
                ->selectRaw('guest_id, SUM(points) as points')
                ->groupBy('guest_id')
                ->get();

            foreach ($sums as $row) {
                $loyalty[(int) $row->guest_id] = ($loyalty[(int) $row->guest_id] ?? 0) + (int) $row->points;
            }
        }

        $goneGuests = array_flip($ids['guests'] ?? []);
        $touched['loyalty'] = array_diff_key($loyalty, $goneGuests);

        return $touched;
    }

    /**
     * What a purge will re-check, in operator terms, for the preview. Reads
     * only; the real repairs are decided after the delete by {@see apply()}.
     *
     * @return list<array{kind: string, count: int, description: string}>
     */
    public function preview(PurgePlan $plan): array
    {
        $captured = $this->capture($plan);
        $repairs = [];

        foreach ([
            'rooms' => ['Rooms', 'room(s) will be re-checked and set back to Available if no stay, cleaning task or maintenance issue still justifies their status.'],
            'dining_tables' => ['Dining tables', 'table(s) will be re-checked and freed if no open order is still using them.'],
            'apartment_units' => ['Apartment units', 'unit(s) will be re-checked and set back to Available if no booking, lease, sale, task or issue still justifies their status.'],
        ] as $key => [$kind, $text]) {
            if ($captured[$key] !== []) {
                $repairs[] = ['kind' => $kind, 'count' => count($captured[$key]), 'description' => count($captured[$key]).' '.$text];
            }
        }

        if ($captured['loyalty'] !== []) {
            $repairs[] = [
                'kind' => 'Loyalty points',
                'count' => count($captured['loyalty']),
                'description' => count($captured['loyalty']).' guest(s) will lose the loyalty points earned on the deleted records.',
            ];
        }

        if ($plan->zeroStock) {
            $repairs[] = [
                'kind' => 'Stock',
                'count' => 1,
                'description' => 'On-hand stock of every product and ingredient will be set to zero.',
            ];
        }

        return $repairs;
    }

    /**
     * Must run AFTER the rows are deleted.
     *
     * @param  array{rooms: list<int>, dining_tables: list<int>, apartment_units: list<int>, guests: list<int>, loyalty: array<int, int>}  $captured
     * @return list<array<string, mixed>>
     */
    public function apply(PurgePlan $plan, array $captured): array
    {
        $tenantId = $plan->tenantId;

        return [
            ...$this->reconcileRooms($tenantId, $captured['rooms']),
            ...$this->reconcileDiningTables($tenantId, $captured['dining_tables']),
            ...$this->reconcileApartmentUnits($tenantId, $captured['apartment_units']),
            ...$this->reconcileGuests($tenantId, $captured['guests'], $captured['loyalty']),
            ...($plan->zeroStock ? $this->zeroStock($tenantId) : []),
        ];
    }

    /**
     * Reverses {@see apply()} for a restore. Numeric deltas are added back
     * (keeping anything the tenant did since); statuses are reset only if
     * nobody changed them in the meantime.
     *
     * @param  list<array<string, mixed>>  $changes
     * @return array{reverted: int, left_alone: int}
     */
    public function revert(int $tenantId, array $changes): array
    {
        $reverted = 0;
        $leftAlone = 0;

        foreach ($changes as $change) {
            $row = DB::table($change['table'])->where('tenant_id', $tenantId)->where('id', $change['id']);

            if ($change['kind'] === 'set') {
                $affected = $row->where($change['column'], $change['new'])->update([$change['column'] => $change['old']]);
            } else {
                $affected = $row->exists()
                    ? DB::table($change['table'])->where('tenant_id', $tenantId)->where('id', $change['id'])->increment($change['column'], -$change['amount'])
                    : 0;
            }

            $affected > 0 ? $reverted++ : $leftAlone++;
        }

        return ['reverted' => $reverted, 'left_alone' => $leftAlone];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reconcileRooms(int $tenantId, array $roomIds): array
    {
        $available = $this->lookup('room_status', 'available');

        if ($roomIds === [] || $available === null) {
            return [];
        }

        $checkedIn = $this->lookup('reservation_status', 'checked_in');
        $openTask = $this->lookups('task_status', ['pending', 'in_progress']);
        $openIssue = $this->lookups('maintenance_status', ['open', 'in_progress']);

        return $this->resetStatuses('rooms', 'room_status_id', $roomIds, $tenantId, $available, [
            $this->lookup('room_status', 'occupied') => fn (array $ids): array => $checkedIn === null ? $ids : DB::table('reservation_rooms as rr')
                ->join('reservations as r', 'r.id', '=', 'rr.reservation_id')
                ->whereIn('rr.room_id', $ids)
                ->where('r.reservation_status_id', $checkedIn)
                ->pluck('rr.room_id')->all(),
            $this->lookup('room_status', 'dirty') => fn (array $ids): array => DB::table('housekeeping_tasks')
                ->whereIn('room_id', $ids)->whereIn('task_status_id', $openTask)->pluck('room_id')->all(),
            $this->lookup('room_status', 'maintenance') => fn (array $ids): array => DB::table('maintenance_issues')
                ->whereIn('room_id', $ids)->whereIn('maintenance_status_id', $openIssue)->pluck('room_id')->all(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reconcileDiningTables(int $tenantId, array $tableIds): array
    {
        $free = $this->lookup('table_status', 'free');

        if ($tableIds === [] || $free === null) {
            return [];
        }

        $openOrder = $this->lookups('order_status', ['open', 'parked']);

        return $this->resetStatuses('dining_tables', 'table_status_id', $tableIds, $tenantId, $free, [
            $this->lookup('table_status', 'occupied') => fn (array $ids): array => DB::table('orders')
                ->whereIn('dining_table_id', $ids)->whereIn('order_status_id', $openOrder)->pluck('dining_table_id')->all(),
            // A table waiting to be cleaned after a deleted order has nothing left to clean.
            $this->lookup('table_status', 'cleaning') => fn (array $ids): array => [],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reconcileApartmentUnits(int $tenantId, array $unitIds): array
    {
        $available = $this->lookup('apartment_unit_status', 'available');

        if ($unitIds === [] || $available === null) {
            return [];
        }

        $checkedIn = $this->lookup('apartment_booking_status', 'checked_in');
        $liveLease = $this->lookups('apartment_lease_status', ['active', 'renewed']);
        $heldSale = $this->lookups('apartment_sale_status', ['reserved', 'agreement_signed']);
        $doneSale = $this->lookups('apartment_sale_status', ['completed']);
        $openTask = $this->lookups('task_status', ['pending', 'in_progress']);
        $openIssue = $this->lookups('maintenance_status', ['open', 'in_progress']);

        return $this->resetStatuses('apartment_units', 'unit_status_id', $unitIds, $tenantId, $available, [
            $this->lookup('apartment_unit_status', 'occupied') => fn (array $ids): array => [
                ...DB::table('apartment_bookings')->whereIn('unit_id', $ids)->where('booking_status_id', $checkedIn)->pluck('unit_id')->all(),
                ...DB::table('apartment_leases')->whereIn('unit_id', $ids)->whereIn('lease_status_id', $liveLease)->pluck('unit_id')->all(),
            ],
            $this->lookup('apartment_unit_status', 'reserved') => fn (array $ids): array => DB::table('apartment_sales')
                ->whereIn('unit_id', $ids)->whereIn('sale_status_id', $heldSale)->pluck('unit_id')->all(),
            $this->lookup('apartment_unit_status', 'sold') => fn (array $ids): array => DB::table('apartment_sales')
                ->whereIn('unit_id', $ids)->whereIn('sale_status_id', $doneSale)->pluck('unit_id')->all(),
            $this->lookup('apartment_unit_status', 'dirty') => fn (array $ids): array => DB::table('apartment_housekeeping_tasks')
                ->whereIn('unit_id', $ids)->whereIn('task_status_id', $openTask)->pluck('unit_id')->all(),
            $this->lookup('apartment_unit_status', 'maintenance') => fn (array $ids): array => DB::table('apartment_maintenance_issues')
                ->whereIn('unit_id', $ids)->whereIn('maintenance_status_id', $openIssue)->pluck('unit_id')->all(),
        ]);
    }

    /**
     * Loyalty points follow the loyalty entries that were deleted; lifetime
     * spend (only ever incremented, never itemised) is zeroed for a guest left
     * with no stay and no event booking at all.
     *
     * @param  list<int>  $guestIds
     * @param  array<int, int>  $deletedPoints  guest id => points on deleted entries
     * @return list<array<string, mixed>>
     */
    private function reconcileGuests(int $tenantId, array $guestIds, array $deletedPoints): array
    {
        $changes = [];

        foreach (array_chunk(array_keys($deletedPoints), self::CHUNK) as $chunk) {
            $guests = DB::table('guests')->where('tenant_id', $tenantId)->whereIn('id', $chunk)->get(['id', 'loyalty_points']);

            foreach ($guests as $guest) {
                $new = max(0, (int) $guest->loyalty_points - $deletedPoints[(int) $guest->id]);
                $delta = $new - (int) $guest->loyalty_points;

                if ($delta !== 0) {
                    DB::table('guests')->where('id', $guest->id)->update(['loyalty_points' => $new]);
                    $changes[] = ['kind' => 'delta', 'table' => 'guests', 'id' => (int) $guest->id, 'column' => 'loyalty_points', 'amount' => $delta];
                }
            }
        }

        foreach (array_chunk($guestIds, self::CHUNK) as $chunk) {
            $withStays = DB::table('reservations')->whereIn('guest_id', $chunk)->pluck('guest_id')
                ->merge(DB::table('venue_bookings')->whereIn('guest_id', $chunk)->pluck('guest_id'))
                ->flip();

            $guests = DB::table('guests')->where('tenant_id', $tenantId)->whereIn('id', $chunk)->where('lifetime_spend', '>', 0)->get(['id', 'lifetime_spend']);

            foreach ($guests as $guest) {
                if (! $withStays->has((int) $guest->id)) {
                    DB::table('guests')->where('id', $guest->id)->update(['lifetime_spend' => 0]);
                    $changes[] = ['kind' => 'delta', 'table' => 'guests', 'id' => (int) $guest->id, 'column' => 'lifetime_spend', 'amount' => -(int) $guest->lifetime_spend];
                }
            }
        }

        return $changes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function zeroStock(int $tenantId): array
    {
        $changes = [];

        DB::table('ingredients')
            ->where('tenant_id', $tenantId)
            ->where('stock_qty', '!=', 0)
            ->orderBy('id')
            ->get(['id', 'stock_qty'])
            ->each(function (object $row) use (&$changes): void {
                $changes[] = ['kind' => 'delta', 'table' => 'ingredients', 'id' => (int) $row->id, 'column' => 'stock_qty', 'amount' => -(float) $row->stock_qty];
            });

        DB::table('ingredients')->where('tenant_id', $tenantId)->update(['stock_qty' => 0]);

        return $changes;
    }

    /**
     * @param  array<int|string, Closure(list<int>): list<int|string>>  $rules  current status id => rows that still justify it
     * @return list<array<string, mixed>>
     */
    private function resetStatuses(string $table, string $column, array $touched, int $tenantId, int $target, array $rules): array
    {
        $rules = array_filter($rules, fn (mixed $rule, mixed $statusId): bool => $statusId !== '' && $statusId !== null, ARRAY_FILTER_USE_BOTH);
        $changes = [];

        foreach (array_chunk($touched, self::CHUNK) as $chunk) {
            $byStatus = [];

            $rows = DB::table($table)->where('tenant_id', $tenantId)->whereIn('id', $chunk)->get(['id', $column]);

            foreach ($rows as $row) {
                if (isset($rules[(int) $row->{$column}])) {
                    $byStatus[(int) $row->{$column}][] = (int) $row->id;
                }
            }

            foreach ($byStatus as $statusId => $ids) {
                $supported = array_flip(array_map('intval', $rules[$statusId]($ids)));
                $reset = array_values(array_filter($ids, fn (int $id): bool => ! isset($supported[$id])));

                if ($reset === []) {
                    continue;
                }

                DB::table($table)->whereIn('id', $reset)->update([$column => $target]);

                foreach ($reset as $id) {
                    $changes[] = ['kind' => 'set', 'table' => $table, 'id' => $id, 'column' => $column, 'old' => $statusId, 'new' => $target];
                }
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, list<int>>  $ids
     * @param  list<array{0: string, 1: string}>  $sources  [table, column] pairs to read
     * @return list<int>
     */
    private function referenced(array $ids, array $sources): array
    {
        $found = [];

        foreach ($sources as [$table, $column]) {
            foreach (array_chunk($ids[$table] ?? [], self::CHUNK) as $chunk) {
                foreach (DB::table($table)->whereIn('id', $chunk)->whereNotNull($column)->distinct()->pluck($column) as $value) {
                    $found[(int) $value] = true;
                }
            }
        }

        return array_keys($found);
    }

    private function lookup(string $type, string $code): ?int
    {
        $key = $type.':'.$code;

        if (! array_key_exists($key, $this->lookupCache)) {
            $id = DB::table('lookups')->where('type', $type)->where('code', $code)->value('id');
            $this->lookupCache[$key] = $id === null ? null : (int) $id;
        }

        return $this->lookupCache[$key];
    }

    /**
     * @param  list<string>  $codes
     * @return list<int>
     */
    private function lookups(string $type, array $codes): array
    {
        return array_values(array_filter(array_map(fn (string $code): ?int => $this->lookup($type, $code), $codes)));
    }
}
