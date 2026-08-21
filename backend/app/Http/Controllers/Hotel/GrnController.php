<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\StoreGrnRequest;
use App\Http\Requests\Hotel\UpdateGrnRequest;
use App\Models\Hotel\Grn;
use App\Services\Hotel\GrnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GrnController extends Controller
{
    private const WITH_LINES = ['lines.ingredient:id,name,unit', 'status', 'creator:id,name'];

    public function __construct(private readonly GrnService $grns) {}

    public function index(Request $request): JsonResponse
    {
        $query = Grn::query()->with(['status', 'creator:id,name'])->withCount('lines')
            ->orderByDesc('received_at')->orderByDesc('id');

        if ($status = $request->string('status')->toString()) {
            $query->whereHas('status', fn ($q) => $q->where('code', $status));
        }
        if ($term = $request->string('q')->toString()) {
            $query->where(fn ($q) => $q->where('grn_no', 'like', "%{$term}%")->orWhere('reference', 'like', "%{$term}%"));
        }

        if ($request->has('page')) {
            return response()->json(['grns' => $query->paginate($request->integer('page_size', 25))->withQueryString()]);
        }

        return response()->json(['grns' => $query->get()]);
    }

    public function store(StoreGrnRequest $request): JsonResponse
    {
        $grn = $this->grns->create($request->validated());

        return response()->json(['message' => "GRN \"{$grn->grn_no}\" created.", 'grn' => $grn], 201);
    }

    public function show(Grn $grn): JsonResponse
    {
        return response()->json(['grn' => $grn->load(self::WITH_LINES)]);
    }

    public function update(UpdateGrnRequest $request, Grn $grn): JsonResponse
    {
        $grn = $this->grns->update($grn, $request->validated());

        return response()->json(['message' => 'GRN updated.', 'grn' => $grn]);
    }

    public function destroy(Grn $grn): JsonResponse
    {
        $grnNo = $grn->grn_no;
        $this->grns->destroy($grn);

        return response()->json(['message' => "GRN \"{$grnNo}\" deleted."]);
    }

    public function receive(Grn $grn): JsonResponse
    {
        $grn = $this->grns->receive($grn);

        return response()->json(['message' => "GRN \"{$grn->grn_no}\" received — stock updated.", 'grn' => $grn]);
    }

    /** Draft only — a received GRN is a posted purchase, corrected via a stock adjustment instead. */
    public function cancel(Grn $grn): JsonResponse
    {
        $grn = $this->grns->cancel($grn);

        return response()->json(['message' => "GRN \"{$grn->grn_no}\" cancelled.", 'grn' => $grn]);
    }
}
