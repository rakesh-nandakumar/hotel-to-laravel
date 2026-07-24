<?php

namespace App\Http\Controllers\Apartment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Apartment\RefundLedgerRequest;
use App\Http\Requests\Apartment\StoreLedgerLineRequest;
use App\Http\Requests\Apartment\StoreLedgerPaymentRequest;
use App\Http\Requests\Apartment\VoidLedgerLineRequest;
use App\Models\Apartment\Ledger;
use App\Models\Apartment\LedgerLine;
use App\Models\Lookup;
use App\Services\Apartment\ApartmentBillingService;
use App\Services\AuditLog;
use App\Support\Lookups\ApartmentLedgerStatus;
use App\Support\Lookups\LookupType;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class LedgerController extends Controller
{
    public function __construct(private readonly ApartmentBillingService $billing) {}

    public function show(Ledger $ledger): JsonResponse
    {
        return response()->json(['ledger' => $this->billing->present($ledger)]);
    }

    /** Add a manual charge line: cleaning fee, extra guest, utility, damage, adjustment. */
    public function addLine(StoreLedgerLineRequest $request, Ledger $ledger): JsonResponse
    {
        $ledger->loadMissing('status');
        if ($ledger->status->code !== ApartmentLedgerStatus::OPEN) {
            throw ValidationException::withMessages(['ledger' => 'Ledger is settled — reopen not allowed.']);
        }

        $data = $request->validated();
        $qty = $data['qty'] ?? 1;

        $line = $ledger->lines()->create([
            'line_source_id' => Lookup::id(LookupType::APARTMENT_LINE_SOURCE, $data['source']),
            'description' => $data['description'],
            'qty' => $qty,
            'unit_price' => $data['unit_price'],
            'amount' => (int) round($qty * $data['unit_price']),
            'staff_id' => $request->user()->id,
        ]);

        AuditLog::record('apartment_ledger.line_added', $line, ['source' => $data['source'], 'amount' => $line->amount]);

        return response()->json(['ledger_line' => $line], 201);
    }

    public function voidLine(VoidLedgerLineRequest $request, LedgerLine $line): JsonResponse
    {
        $line->loadMissing('ledger.status');
        if ($line->ledger->status->code !== ApartmentLedgerStatus::OPEN) {
            throw ValidationException::withMessages(['ledger' => 'Ledger already settled.']);
        }

        $reason = $request->validated('reason');
        $line->update(['voided' => true, 'void_reason' => $reason]);

        AuditLog::record('apartment_ledger.line_voided', $line, ['reason' => $reason, 'amount' => $line->amount]);

        return response()->json(['message' => 'Ledger line voided.']);
    }

    public function payment(StoreLedgerPaymentRequest $request, Ledger $ledger): JsonResponse
    {
        $data = $request->validated();

        $payment = $this->billing->recordPayment([
            'ledger_id' => $ledger->id,
            'method' => $data['method'],
            'amount' => $data['amount'],
            'kind' => $data['kind'] ?? 'payment',
            'reference' => $data['reference'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'staff_id' => $request->user()->id,
        ]);

        return response()->json(['payment' => $payment], 201);
    }

    public function refund(RefundLedgerRequest $request, Ledger $ledger): JsonResponse
    {
        $totals = $this->billing->totals($ledger);
        $data = $request->validated();

        if ($data['amount'] > $totals['paid'] - $totals['refunded']) {
            throw ValidationException::withMessages(['amount' => 'Refund exceeds net amount paid.']);
        }

        $payment = $this->billing->recordPayment([
            'ledger_id' => $ledger->id,
            'method' => $data['method'],
            'amount' => $data['amount'],
            'kind' => 'refund',
            'reason' => $data['reason'],
            'staff_id' => $request->user()->id,
        ]);

        return response()->json(['payment' => $payment], 201);
    }
}
