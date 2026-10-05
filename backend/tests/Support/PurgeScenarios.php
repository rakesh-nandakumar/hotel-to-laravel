<?php

namespace Tests\Support;

use App\Models\CentralAdmin;
use App\Models\Tenant;
use App\Models\TenantDataPurge;
use App\Services\Tenancy\DataPurge\DataPurgeService;
use App\Services\Tenancy\DataPurge\PurgeCatalog;
use App\Services\Tenancy\DataPurge\PurgeGraph;
use App\Services\Tenancy\DataPurge\PurgePlan;
use App\Services\Tenancy\DataPurge\PurgePlanner;
use Illuminate\Support\Facades\DB;

/**
 * Shared set-up and assertions for the data purge / restore tests.
 */
final class PurgeScenarios
{
    /** The tables a fully built stay occupies, keyed by the array key {@see stay()} returns. */
    public const STAY_TABLES = [
        'guest' => 'guests',
        'reservation' => 'reservations',
        'link' => 'reservation_rooms',
        'folio' => 'folios',
        'line' => 'folio_lines',
        'payment' => 'payments',
        'loyalty' => 'loyalty_transactions',
    ];

    /**
     * @param  list<string>  $keys  category keys
     * @param  array<string, array<string, mixed>>  $filters  category key => filter key => value
     * @return list<array{key: string, filters: array<string, mixed>}>
     */
    public static function selections(array $keys, array $filters = []): array
    {
        return app(PurgeCatalog::class)->normalize(array_map(
            fn (string $key): array => ['key' => $key, 'filters' => $filters[$key] ?? []],
            $keys,
        ));
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, array<string, mixed>>  $filters
     */
    public static function plan(Tenant $tenant, array $keys, array $filters = []): PurgePlan
    {
        return app(PurgePlanner::class)->plan($tenant->id, self::selections($keys, $filters));
    }

    /**
     * Previews and then executes a purge, like an operator would.
     *
     * @param  list<string>  $keys
     * @param  array<string, array<string, mixed>>  $filters
     */
    public static function purge(Tenant $tenant, CentralAdmin $admin, array $keys, array $filters = [], bool $continueNumbering = false): TenantDataPurge
    {
        $selections = self::selections($keys, $filters);
        $plan = app(PurgePlanner::class)->plan($tenant->id, $selections);

        return app(DataPurgeService::class)->execute($tenant, $selections, $plan->fingerprint, $admin, $continueNumbering, null);
    }

    /**
     * Row count per tenant table for one tenant.
     *
     * @return array<string, int>
     */
    public static function counts(int $tenantId): array
    {
        return collect(app(PurgeGraph::class)->tenantTables())
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->where('tenant_id', $tenantId)->count()])
            ->all();
    }

    /**
     * Every row of the given tables (default: every purgeable table) for one
     * tenant, by id — for exact before/after comparisons.
     *
     * @param  list<string>|null  $tables
     * @return array<string, list<array<string, mixed>>>
     */
    public static function dump(int $tenantId, ?array $tables = null): array
    {
        return collect($tables ?? app(PurgeGraph::class)->scopeTables())
            ->mapWithKeys(fn (string $table): array => [
                $table => DB::table($table)->where('tenant_id', $tenantId)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            ])
            ->all();
    }

    public static function exists(string $table, int $id): bool
    {
        return DB::table($table)->where('id', $id)->exists();
    }

    /**
     * @param  array<string, int>  $stay  the array {@see stay()} returned
     * @return array<string, bool> table => still there?
     */
    public static function stayAlive(array $stay): array
    {
        $alive = [];

        foreach (self::STAY_TABLES as $key => $table) {
            $alive[$table] = self::exists($table, $stay[$key]);
        }

        return $alive;
    }

    /**
     * @return array<string, bool> every stay table => $state
     */
    public static function stayState(bool $state): array
    {
        return array_fill_keys(array_values(self::STAY_TABLES), $state);
    }

    /**
     * A guest's stay with everything an invoice drags along: room assignment,
     * invoice (folio), charge line, payment and a loyalty entry.
     *
     * @param  array<string, mixed>  $o  guest, room, code, invoice, check_in, check_out, status, points, guest_created
     * @return array<string, int> ids by role
     */
    public static function stay(PurgeFixtures $f, int $tenantId, array $o = []): array
    {
        $guest = $o['guest'] ?? $f->row('guests', $tenantId, ['created_at' => $o['guest_created'] ?? '2026-01-05 10:00:00']);
        $room = $o['room'] ?? $f->row('rooms', $tenantId);

        $reservation = $f->row('reservations', $tenantId, [
            'guest_id' => $guest,
            'code' => $o['code'] ?? 'RSV-0001',
            'check_in' => $o['check_in'] ?? '2026-03-10',
            'check_out' => $o['check_out'] ?? '2026-03-12',
            'reservation_status_id' => $o['status'] ?? $f->lookup('reservation_status', 'confirmed'),
        ]);

        $folio = $f->row('folios', $tenantId, ['reservation_id' => $reservation, 'invoice_no' => $o['invoice'] ?? 'INV-2026-0001']);

        return [
            'guest' => $guest,
            'room' => $room,
            'reservation' => $reservation,
            'link' => $f->row('reservation_rooms', $tenantId, ['reservation_id' => $reservation, 'room_id' => $room]),
            'folio' => $folio,
            'line' => $f->row('folio_lines', $tenantId, ['folio_id' => $folio]),
            'payment' => $f->row('payments', $tenantId, ['folio_id' => $folio]),
            'loyalty' => $f->row('loyalty_transactions', $tenantId, [
                'guest_id' => $guest,
                'points' => $o['points'] ?? 10,
                'ref_type' => 'FOLIO',
                'ref_id' => $folio,
            ]),
        ];
    }
}
