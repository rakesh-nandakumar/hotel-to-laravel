<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\RefundFolioRequest;
use App\Http\Requests\Hotel\StoreFolioLineRequest;
use App\Http\Requests\Hotel\StoreFolioPaymentRequest;
use App\Http\Requests\Hotel\VoidFolioLineRequest;
use App\Models\Hotel\Folio;
use App\Models\Hotel\FolioLine;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Services\Hotel\BillingService;
use App\Services\Hotel\Pdf\PdfService;
use App\Support\Lookups\FolioStatus;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class FolioController extends Controller
{
    public function __construct(private readonly BillingService $billing, private readonly PdfService $pdf) {}

    public function show(Folio $folio): JsonResponse
    {
        return response()->json(['folio' => $this->billing->present($folio)]);
    }

    /** Add a manual charge line: minibar, damage/replacement, adjustment, venue extras. */
    public function addLine(StoreFolioLineRequest $request, Folio $folio): JsonResponse
    {
        $folio->loadMissing('status');
        if ($folio->status->code !== FolioStatus::OPEN) {
            throw ValidationException::withMessages(['folio' => 'Folio is settled — reopen not allowed.']);
        }

        $data = $request->validated();
        $qty = $data['qty'] ?? 1;

        $line = $folio->lines()->create([
            'line_source_id' => Lookup::id(LookupType::LINE_SOURCE, $data['source']),
            'description' => $data['description'],
            'qty' => $qty,
            'unit_price' => $data['unit_price'],
            'amount' => (int) round($qty * $data['unit_price']),
            'staff_id' => $request->user()->id,
        ]);

        AuditLog::record('folio.line_added', $line, ['source' => $data['source'], 'amount' => $line->amount]);

        return response()->json(['folio_line' => $line], 201);
    }

    /** Void a line — mandatory reason, keeps the audit trail. */
    public function voidLine(VoidFolioLineRequest $request, FolioLine $line): JsonResponse
    {
        $line->loadMissing('folio.status');
        if ($line->folio->status->code !== FolioStatus::OPEN) {
            throw ValidationException::withMessages(['folio' => 'Folio already settled.']);
        }

        $reason = $request->validated('reason');
        $line->update(['voided' => true, 'void_reason' => $reason]);

        AuditLog::record('folio.line_voided', $line, ['reason' => $reason, 'amount' => $line->amount]);

        return response()->json(['message' => 'Folio line voided.']);
    }

    /** Payment against a folio (deposit / interim / mixed methods). */
    public function payment(StoreFolioPaymentRequest $request, Folio $folio): JsonResponse
    {
        $folio->loadMissing('reservation');
        $data = $request->validated();

        if ($data['method'] === PaymentMethod::CORPORATE_CREDIT && ! $folio->reservation?->corporate_account_id) {
            throw ValidationException::withMessages(['method' => 'Corporate credit only on corporate bookings.']);
        }

        $payment = $this->billing->recordPayment([
            'folio_id' => $folio->id,
            'method' => $data['method'],
            'amount' => $data['amount'],
            'kind' => $data['kind'] ?? PaymentKind::PAYMENT,
            'reference' => $data['reference'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'staff_id' => $request->user()->id,
            'guest_id_for_loyalty' => $folio->reservation?->guest_id,
        ]);

        return response()->json(['payment' => $payment], 201);
    }

    /** Refund from a folio — mandatory reason, capped at net amount paid. */
    public function refund(RefundFolioRequest $request, Folio $folio): JsonResponse
    {
        $totals = $this->billing->totals($folio);
        $data = $request->validated();

        if ($data['amount'] > $totals['paid'] - $totals['refunded']) {
            throw ValidationException::withMessages(['amount' => 'Refund exceeds net amount paid.']);
        }

        $payment = $this->billing->recordPayment([
            'folio_id' => $folio->id,
            'method' => $data['method'],
            'amount' => $data['amount'],
            'kind' => PaymentKind::REFUND,
            'reason' => $data['reason'],
            'staff_id' => $request->user()->id,
        ]);

        return response()->json(['payment' => $payment], 201);
    }

    /** Branded invoice PDF — ?format=thermal|a4 (guest INV / venue VNU types). */
    public function invoice(Request $request, Folio $folio): Response
    {
        $format = $request->query('format') === 'thermal' ? 'thermal' : 'a4';

        return $this->pdf->folioInvoice($folio, $format);
    }
}
