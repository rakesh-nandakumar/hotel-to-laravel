<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\StoreCorporateAccountRequest;
use App\Http\Requests\Hotel\StoreCorporateSettlementRequest;
use App\Http\Requests\Hotel\UpdateCorporateAccountRequest;
use App\Models\Hotel\CorporateAccount;
use App\Models\Hotel\Payment;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Services\Hotel\BillingService;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\PaymentMethod;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorporateAccountController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    public function index(Request $request): JsonResponse
    {
        $query = CorporateAccount::query()->orderBy('company_name');
        $accounts = $query->paginate($request->integer('page_size', 15))->withQueryString();

        $accountIds = $accounts->getCollection()->pluck('id');
        $corporateCreditMethodId = Lookup::id(LookupType::PAYMENT_METHOD, PaymentMethod::CORPORATE_CREDIT);

        // Outstanding = Σ CORPORATE_CREDIT charges on the account's reservations' folios − Σ settlements.
        $charges = Payment::query()
            ->join('folios', 'folios.id', '=', 'payments.folio_id')
            ->join('reservations', 'reservations.id', '=', 'folios.reservation_id')
            ->where('payments.payment_method_id', $corporateCreditMethodId)
            ->whereIn('reservations.corporate_account_id', $accountIds)
            ->selectRaw('reservations.corporate_account_id as account_id, sum(payments.amount) as total')
            ->groupBy('reservations.corporate_account_id')
            ->pluck('total', 'account_id');

        $settlements = Payment::query()
            ->whereIn('corporate_account_id', $accountIds)
            ->selectRaw('corporate_account_id as account_id, sum(amount) as total')
            ->groupBy('corporate_account_id')
            ->pluck('total', 'account_id');

        $accounts->getCollection()->each(function (CorporateAccount $account) use ($charges, $settlements) {
            $account->outstanding = (int) ($charges[$account->id] ?? 0) - (int) ($settlements[$account->id] ?? 0);
        });

        return response()->json(['corporate_accounts' => $accounts]);
    }

    public function store(StoreCorporateAccountRequest $request): JsonResponse
    {
        $account = CorporateAccount::create($request->validated());

        AuditLog::record('corporate.created', $account, ['name' => $account->company_name]);

        return response()->json(['message' => "Corporate account \"{$account->company_name}\" created.", 'corporate_account' => $account], 201);
    }

    public function update(UpdateCorporateAccountRequest $request, CorporateAccount $corporateAccount): JsonResponse
    {
        $corporateAccount->update($request->validated());

        AuditLog::record('corporate.updated', $corporateAccount, ['name' => $corporateAccount->company_name]);

        return response()->json(['message' => 'Corporate account updated.', 'corporate_account' => $corporateAccount]);
    }

    /** Month-end statement: all CORPORATE_CREDIT charges in the month + settlements. */
    public function statement(Request $request, CorporateAccount $corporateAccount): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $month = $request->string('month')->toString() ?: now()->format('Y-m');
        $from = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $to = $from->clone()->addMonth();

        $corporateCreditMethodId = Lookup::id(LookupType::PAYMENT_METHOD, PaymentMethod::CORPORATE_CREDIT);

        $charges = Payment::query()
            ->where('payment_method_id', $corporateCreditMethodId)
            ->whereBetween('payments.created_at', [$from, $to])
            ->whereHas('folio.reservation', fn ($q) => $q->where('corporate_account_id', $corporateAccount->id))
            ->with(['folio.reservation.guest:id,name'])
            ->oldest()
            ->get();

        $settlements = Payment::query()
            ->where('corporate_account_id', $corporateAccount->id)
            ->whereBetween('created_at', [$from, $to])
            ->with('method')
            ->oldest()
            ->get();

        return response()->json([
            'account' => $corporateAccount,
            'month' => $month,
            'charges' => $charges->map(fn (Payment $p) => [
                'id' => $p->id,
                'date' => $p->created_at,
                'amount' => $p->amount,
                'reservation' => $p->folio?->reservation?->code,
                'guest' => $p->folio?->reservation?->guest?->name,
                'invoice_no' => $p->folio?->invoice_no,
            ])->values(),
            'settlements' => $settlements,
            'total_charges' => $charges->sum('amount'),
            'total_settled' => $settlements->sum('amount'),
        ]);
    }

    /** Record a month-end settlement payment from the company. */
    public function settle(StoreCorporateSettlementRequest $request, CorporateAccount $corporateAccount): JsonResponse
    {
        $data = $request->validated();

        $payment = $this->billing->recordPayment([
            'corporate_account_id' => $corporateAccount->id,
            'method' => $data['method'],
            'amount' => $data['amount'],
            'reference' => $data['reference'] ?? null,
            'staff_id' => $request->user()->id,
        ]);

        AuditLog::record('corporate.settled', $corporateAccount, [
            'name' => $corporateAccount->company_name, 'amount' => $data['amount'], 'method' => $data['method'],
        ]);

        return response()->json(['payment' => $payment], 201);
    }
}
