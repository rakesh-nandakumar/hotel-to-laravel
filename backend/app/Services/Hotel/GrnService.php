<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Grn;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Services\DocumentNumberService;
use App\Support\Lookups\GrnStatus;
use App\Support\Lookups\LookupType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Goods Received Note lifecycle — draft editing, then a one-way post
 * (`receive()`) into batches + stock movements. No supplier entity: a GRN
 * only records a free-text `reference` (supplier invoice/bill number).
 */
class GrnService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    /**
     * @param  array{reference?: ?string, received_at: string, notes?: ?string, lines: list<array<string, mixed>>}  $data
     */
    public function create(array $data): Grn
    {
        return DB::transaction(function () use ($data) {
            $grn = Grn::create([
                'grn_no' => $this->documentNumbers->next(Grn::class, 'grn_no', 'GRN-'),
                'reference' => $data['reference'] ?? null,
                'grn_status_id' => Lookup::id(LookupType::GRN_STATUS, GrnStatus::DRAFT),
                'received_at' => $data['received_at'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($grn, $data['lines']);

            AuditLog::record('grn.created', $grn, ['grn_no' => $grn->grn_no]);

            return $grn->fresh('lines.ingredient');
        });
    }

    /**
     * @param  array{reference?: ?string, received_at?: string, notes?: ?string, lines?: list<array<string, mixed>>}  $data
     */
    public function update(Grn $grn, array $data): Grn
    {
        $grn->loadMissing('status');
        if ($grn->status->code !== GrnStatus::DRAFT) {
            throw ValidationException::withMessages(['grn' => 'Only a draft GRN can be edited.']);
        }

        return DB::transaction(function () use ($grn, $data) {
            $grn->update([
                'reference' => $data['reference'] ?? $grn->reference,
                'received_at' => $data['received_at'] ?? $grn->received_at,
                'notes' => $data['notes'] ?? $grn->notes,
            ]);

            if (array_key_exists('lines', $data)) {
                $this->syncLines($grn, $data['lines']);
            }

            AuditLog::record('grn.updated', $grn, ['grn_no' => $grn->grn_no]);

            return $grn->fresh('lines.ingredient');
        });
    }

    public function destroy(Grn $grn): void
    {
        $grn->loadMissing('status');
        if ($grn->status->code !== GrnStatus::DRAFT) {
            throw ValidationException::withMessages(['grn' => 'Only a draft GRN can be deleted.']);
        }

        $grnNo = $grn->grn_no;
        $grn->delete();

        AuditLog::record('grn.deleted', $grn, ['grn_no' => $grnNo]);
    }

    /**
     * Post the GRN: one batch per line, stock incremented, a grn_receipt
     * movement per line, each touched ingredient's unit_cost set to its
     * newest received line's cost (latest purchase cost) — selling_price
     * never moves on receipt. A received GRN is never un-posted; correct
     * mistakes with a stock adjustment instead.
     */
    public function receive(Grn $grn): Grn
    {
        $grn->loadMissing('status', 'lines');
        if ($grn->status->code !== GrnStatus::DRAFT) {
            throw ValidationException::withMessages(['grn' => 'Only a draft GRN can be received.']);
        }
        if ($grn->lines->isEmpty()) {
            throw ValidationException::withMessages(['grn' => 'Add at least one line before receiving.']);
        }

        DB::transaction(function () use ($grn) {
            $this->inventory->receiveGrn($grn);

            $grn->update(['grn_status_id' => Lookup::id(LookupType::GRN_STATUS, GrnStatus::RECEIVED)]);
        });

        AuditLog::record('grn.received', $grn, [
            'grn_no' => $grn->grn_no, 'total_cost' => $grn->total_cost, 'lines' => $grn->lines->count(),
        ]);

        return $grn->fresh(['lines.ingredient', 'status']);
    }

    /** Draft only — a received GRN is a posted purchase, never cancelled after the fact. */
    public function cancel(Grn $grn): Grn
    {
        $grn->loadMissing('status');
        if ($grn->status->code !== GrnStatus::DRAFT) {
            throw ValidationException::withMessages([
                'grn' => 'Only a draft GRN can be cancelled — a received GRN is never un-posted, correct it with a stock adjustment.',
            ]);
        }

        $grn->update(['grn_status_id' => Lookup::id(LookupType::GRN_STATUS, GrnStatus::CANCELLED)]);

        AuditLog::record('grn.cancelled', $grn, ['grn_no' => $grn->grn_no]);

        return $grn->fresh('status');
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function syncLines(Grn $grn, array $lines): void
    {
        $grn->lines()->delete();

        $totalCost = 0;
        foreach ($lines as $line) {
            $lineTotal = (int) round($line['qty'] * $line['unit_cost']);
            $totalCost += $lineTotal;

            $grn->lines()->create([
                'ingredient_id' => $line['ingredient_id'],
                'qty' => $line['qty'],
                'unit_cost' => $line['unit_cost'],
                'line_total' => $lineTotal,
                'batch_no' => $line['batch_no'] ?? null,
                'manufactured_at' => $line['manufactured_at'] ?? null,
                'expiry_date' => $line['expiry_date'] ?? null,
            ]);
        }

        $grn->update(['total_cost' => $totalCost]);
    }
}
