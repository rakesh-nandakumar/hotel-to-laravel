<?php

namespace App\Http\Controllers;

use App\Http\Requests\Till\CloseTillRequest;
use App\Http\Requests\Till\OpenTillRequest;
use App\Http\Requests\Till\StoreTillMovementRequest;
use App\Http\Requests\Till\StoreTillRequest;
use App\Http\Requests\Till\UpdateTillRequest;
use App\Models\Till;
use App\Models\TillSession;
use App\Services\CurrentContext;
use App\Services\TillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TillController extends Controller
{
    public function __construct(private readonly TillService $till, private readonly CurrentContext $context) {}

    /** Tills the current user may open — active tills in their accessible branches. */
    public function index(): JsonResponse
    {
        $query = Till::query()->active()->with('branch:id,name')->orderBy('name');

        $branchIds = $this->context->accessibleBranchIds();
        if ($branchIds !== null) {
            $query->whereIn('branch_id', $branchIds);
        }

        return response()->json(['tills' => $query->get()]);
    }

    public function store(StoreTillRequest $request): JsonResponse
    {
        $till = Till::create($request->validated());

        return response()->json(['till' => $till], 201);
    }

    public function update(UpdateTillRequest $request, Till $till): JsonResponse
    {
        $till->update($request->validated());

        return response()->json(['till' => $till]);
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
        );

        return response()->json(['session' => $session->load('till:id,name')], 201);
    }

    public function close(CloseTillRequest $request, TillSession $session): JsonResponse
    {
        $data = $request->validated();
        $closed = $this->till->closeTill($session, $data['closing_cash'], $data['notes'] ?? null, $request->user());

        return response()->json(['session' => $closed]);
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
