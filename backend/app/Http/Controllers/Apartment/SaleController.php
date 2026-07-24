<?php

namespace App\Http\Controllers\Apartment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Apartment\CancelSaleRequest;
use App\Http\Requests\Apartment\ReserveSaleRequest;
use App\Http\Requests\Apartment\StoreSaleRequest;
use App\Models\Apartment\Sale;
use App\Services\Apartment\ApartmentBillingService;
use App\Services\Apartment\ApartmentSalesService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __construct(
        private readonly ApartmentSalesService $sales,
        private readonly ApartmentBillingService $billing,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Sale::query()->with(['customer:id,name,phone', 'status', 'unit:id,unit_no'])->orderByDesc('created_at');

        if ($status = $request->string('status')->toString()) {
            $query->statusCode($status);
        }

        if ($term = $request->string('q')->toString()) {
            $query->where(function (Builder $q) use ($term) {
                $q->where('code', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('unit', fn (Builder $u) => $u->where('unit_no', 'like', "%{$term}%"));
            });
        }

        if ($request->has('page')) {
            return response()->json(['sales' => $query->paginate($request->integer('page_size', 20))->withQueryString()]);
        }

        return response()->json(['sales' => $query->limit(200)->get()]);
    }

    public function show(Sale $sale): JsonResponse
    {
        $sale->load(['customer', 'status', 'unit.unitType', 'unit.status', 'unit.property']);

        $ledger = $sale->ledger;

        return response()->json([
            'sale' => $sale,
            'ledger' => $ledger ? $this->billing->present($ledger) : null,
        ]);
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        $sale = $this->sales->create($request->validated(), $request->user()->id);

        return response()->json(['message' => "Sale \"{$sale->code}\" created.", 'sale' => $sale], 201);
    }

    public function reserve(ReserveSaleRequest $request, Sale $sale): JsonResponse
    {
        $sale = $this->sales->reserve($sale, $request->validated(), $request->user()->id);

        return response()->json(['message' => 'Sale reserved — unit held.', 'sale' => $sale]);
    }

    public function signAgreement(Request $request, Sale $sale): JsonResponse
    {
        abort_unless($request->user()?->hasPermissionTo('apartment_sales.sign_agreement'), 403);

        $sale = $this->sales->signAgreement($sale, $request->user()->id);

        return response()->json(['message' => 'Agreement signed.', 'sale' => $sale]);
    }

    public function complete(Request $request, Sale $sale): JsonResponse
    {
        abort_unless($request->user()?->hasPermissionTo('apartment_sales.complete'), 403);

        $sale = $this->sales->complete($sale, $request->user()->id);

        return response()->json(['message' => 'Sale completed — unit marked sold.', 'sale' => $sale]);
    }

    public function cancel(CancelSaleRequest $request, Sale $sale): JsonResponse
    {
        $result = $this->sales->cancel($sale, $request->validated('reason'), $request->user()->id);

        return response()->json($result);
    }
}
