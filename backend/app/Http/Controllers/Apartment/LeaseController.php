<?php

namespace App\Http\Controllers\Apartment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Apartment\RenewLeaseRequest;
use App\Http\Requests\Apartment\StoreLeaseRequest;
use App\Http\Requests\Apartment\StoreUtilityReadingRequest;
use App\Http\Requests\Apartment\TerminateLeaseRequest;
use App\Models\Apartment\Lease;
use App\Services\Apartment\ApartmentBillingService;
use App\Services\Apartment\ApartmentLeaseBillingService;
use App\Services\Apartment\ApartmentLeaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaseController extends Controller
{
    public function __construct(
        private readonly ApartmentLeaseService $leases,
        private readonly ApartmentLeaseBillingService $leaseBilling,
        private readonly ApartmentBillingService $billing,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Lease::query()->with(['customer:id,name,phone', 'status', 'unit:id,unit_no'])->orderByDesc('start_date');

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
            return response()->json(['leases' => $query->paginate($request->integer('page_size', 20))->withQueryString()]);
        }

        return response()->json(['leases' => $query->limit(200)->get()]);
    }

    public function show(Lease $lease): JsonResponse
    {
        $lease->load(['customer', 'status', 'unit.unitType', 'unit.status', 'unit.property', 'utilityReadings.utilityType']);

        $ledger = $lease->ledger;

        return response()->json([
            'lease' => $lease,
            'ledger' => $ledger ? $this->billing->present($ledger) : null,
        ]);
    }

    public function store(StoreLeaseRequest $request): JsonResponse
    {
        $lease = $this->leases->create($request->validated(), $request->user()->id);

        return response()->json(['message' => "Lease \"{$lease->code}\" created.", 'lease' => $lease], 201);
    }

    public function renew(RenewLeaseRequest $request, Lease $lease): JsonResponse
    {
        $lease = $this->leases->renew($lease, $request->validated('end_date'), $request->user()->id);

        return response()->json(['message' => 'Lease renewed.', 'lease' => $lease]);
    }

    public function terminate(TerminateLeaseRequest $request, Lease $lease): JsonResponse
    {
        $lease = $this->leases->terminate($lease, $request->validated('reason'), $request->user()->id);

        return response()->json(['message' => 'Lease terminated.', 'lease' => $lease]);
    }

    public function storeUtilityReading(StoreUtilityReadingRequest $request, Lease $lease): JsonResponse
    {
        $reading = $this->leaseBilling->recordUtilityReading($lease, $request->validated(), $request->user()->id);

        return response()->json(['utility_reading' => $reading->load('utilityType')], 201);
    }
}
