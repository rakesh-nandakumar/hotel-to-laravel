<?php

namespace App\Http\Controllers;

use App\Http\Requests\Till\CloseTillRequest;
use App\Http\Requests\Till\OpenTillRequest;
use App\Http\Requests\Till\StoreTillMovementRequest;
use App\Models\Till;
use App\Models\TillSession;
use App\Services\TillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TillController extends Controller
{
    public function __construct(private readonly TillService $till) {}

    /**
     * Tills the current user may open — every active till in the tenant, each
     * with its last closing count and who counted it when (all null if the
     * till has never been closed), so the open-till screen can suggest an
     * opening amount and show where that suggestion came from.
     * ?include_inactive=1 also returns deactivated tills — till definitions
     * themselves are managed only from master control
     * (App\Http\Controllers\Central\TenantTillController), this stays read-only.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Till::query()->orderBy('name');
        if (! $request->boolean('include_inactive')) {
            $query->active();
        }

        $tills = $query->get();
        $lastClosings = $this->till->lastClosings($tills->pluck('id')->all());
        $tills->each(function (Till $till) use ($lastClosings): void {
            $last = $lastClosings[$till->id] ?? null;
            $till->last_closing_cash = $last['cash'] ?? null;
            $till->last_closed_at = $last['closed_at'] ?? null;
            $till->last_closed_by = $last['closed_by'] ?? null;
        });

        return response()->json(['tills' => $tills]);
    }

    /** My open till session (POS/Folio/Apartment payment screens show drawer state). */
    public function current(Request $request): JsonResponse
    {
        return response()->json(['session' => $this->till->present($request->user()->id)]);
    }

    public function open(OpenTillRequest $request): JsonResponse
    {
        $session = $this->till->openTill(
            $request->validated('till_id'),
            $request->user()->id,
            $request->validated('opening_balance'),
            $request->validated('reason'),
        );

        return response()->json(['session' => $session->load('till:id,name')], 201);
    }

    public function close(CloseTillRequest $request, TillSession $session): JsonResponse
    {
        $data = $request->validated();
        $closed = $this->till->closeTill($session, $data['closing_cash'] ?? null, $data['reason'] ?? null, $data['notes'] ?? null, $request->user());

        return response()->json(['session' => $closed]);
    }

    /**
     * The close-out breakdown for a session — every activity that put cash in
     * or took it out, and the expected balance those movements add up to. Read
     * live on the Till page and again inside the close dialog, so the operator
     * reconciles against an itemised statement rather than a bare total.
     */
    public function summary(TillSession $session): JsonResponse
    {
        return response()->json(['summary' => $this->till->sessionSummary($session)]);
    }

    /** Full ledger for one session, oldest first — the audit trail a variance dispute gets resolved against. */
    public function movements(Request $request, TillSession $session): JsonResponse
    {
        $query = $session->movements()->with(['type', 'performedBy:id,name', 'approvedBy:id,name'])->oldest();

        if ($request->has('page')) {
            return response()->json(['movements' => $query->paginate($request->integer('page_size', 50))->withQueryString()]);
        }

        return response()->json(['movements' => $query->get()]);
    }

    /** Manual cash-in (float top-up) / cash-out (withdrawal, expense, transfer) — mandatory reason for anything leaving the drawer. */
    public function storeMovement(StoreTillMovementRequest $request, TillSession $session): JsonResponse
    {
        $data = $request->validated();

        $movement = $this->till->recordMovement(
            $session,
            $data['type'],
            $data['amount'],
            $request->user()->id,
            reason: $data['reason'] ?? null,
            reference: $data['reference'] ?? null,
            notes: $data['notes'] ?? null,
            approvedBy: $data['approved_by'] ?? null,
        );

        return response()->json(['movement' => $movement->load(['type', 'performedBy:id,name', 'approvedBy:id,name'])], 201);
    }

    /** Session history — for reconciliation and the Till variance report. */
    public function sessions(Request $request): JsonResponse
    {
        $query = TillSession::query()->with('till:id,name', 'staff:id,name', 'status')->latest('opened_at');

        if ($request->has('page')) {
            return response()->json(['sessions' => $query->paginate($request->integer('page_size', 25))->withQueryString()]);
        }

        return response()->json(['sessions' => $query->limit(60)->get()]);
    }
}
