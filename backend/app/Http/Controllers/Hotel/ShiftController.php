<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\CloseShiftRequest;
use App\Http\Requests\Hotel\OpenShiftRequest;
use App\Models\Hotel\Shift;
use App\Services\Hotel\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function __construct(private readonly ShiftService $shifts) {}

    /** My open shift (POS shows drawer state). */
    public function current(Request $request): JsonResponse
    {
        return response()->json(['shift' => $this->shifts->currentShift($request->user()->id)]);
    }

    public function open(OpenShiftRequest $request): JsonResponse
    {
        $shift = $this->shifts->openShift($request->user()->id, $request->validated('opening_cash'));

        return response()->json(['shift' => $shift], 201);
    }

    public function close(CloseShiftRequest $request, Shift $shift): JsonResponse
    {
        $data = $request->validated();
        $closed = $this->shifts->closeShift($shift, $data['closing_cash'], $data['notes'] ?? null, $request->user());

        return response()->json(['shift' => $closed]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Shift::query()->with('staff:id,name')->latest('opened_at');

        if ($request->has('page')) {
            return response()->json(['shifts' => $query->paginate($request->integer('page_size', 25))->withQueryString()]);
        }

        return response()->json(['shifts' => $query->limit(60)->get()]);
    }
}
