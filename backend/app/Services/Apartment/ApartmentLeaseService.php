<?php

namespace App\Services\Apartment;

use App\Models\Apartment\Customer;
use App\Models\Apartment\HousekeepingTask;
use App\Models\Apartment\Lease;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Services\DocumentNumberService;
use App\Support\Lookups\ApartmentLeaseStatus;
use App\Support\Lookups\ApartmentLedgerStatus;
use App\Support\Lookups\ApartmentUnitStatus;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\PaymentKind;
use App\Support\Lookups\TaskStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Long-term lease lifecycle — create (signs immediately, moves the unit to
 * OCCUPIED), renew (extends the term), terminate (ends it, frees the unit).
 * Recurring rent/utility billing lives in {@see ApartmentLeaseBillingService};
 * this service only owns the lease record and the unit's occupancy state.
 */
class ApartmentLeaseService
{
    public function __construct(
        private readonly ApartmentAvailabilityService $availability,
        private readonly ApartmentBillingService $billing,
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $staffId): Lease
    {
        $startDate = Carbon::parse($data['start_date'])->startOfDay();
        $endDate = ! empty($data['end_date']) ? Carbon::parse($data['end_date'])->startOfDay() : null;

        return DB::transaction(function () use ($data, $startDate, $endDate, $staffId) {
            $customerId = $data['customer_id'] ?? null;
            if (! $customerId) {
                $customerId = Customer::create($data['new_customer'])->id;
            }

            $unit = $this->availability->assertUnitAvailableForLease($data['unit_id'], $startDate, $endDate);

            $lease = Lease::create([
                'code' => $this->documentNumbers->next(Lease::class, 'code', 'LSE-'),
                'unit_id' => $unit->id,
                'customer_id' => $customerId,
                'lease_status_id' => Lookup::id(LookupType::APARTMENT_LEASE_STATUS, ApartmentLeaseStatus::ACTIVE),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'monthly_rent' => $data['monthly_rent'],
                'rent_due_day' => $data['rent_due_day'] ?? 1,
                'security_deposit' => $data['security_deposit'] ?? 0,
                'notice_period_days' => $data['notice_period_days'] ?? 30,
                'auto_renew' => $data['auto_renew'] ?? false,
                'signed_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $ledger = $lease->ledger()->create([
                'ledger_status_id' => Lookup::id(LookupType::APARTMENT_LEDGER_STATUS, ApartmentLedgerStatus::OPEN),
            ]);

            if (! empty($data['deposit_payment'])) {
                $this->billing->recordPayment([
                    'ledger_id' => $ledger->id,
                    'method' => $data['deposit_payment']['method'],
                    'amount' => $data['deposit_payment']['amount'],
                    'kind' => PaymentKind::DEPOSIT,
                    'reference' => $data['deposit_payment']['reference'] ?? null,
                    'staff_id' => $staffId,
                ]);
            }

            $unit->update(['unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::OCCUPIED)]);

            AuditLog::record('apartment_lease.created', $lease, [
                'code' => $lease->code, 'monthly_rent' => $lease->monthly_rent,
            ]);

            return $lease->load(['customer', 'unit.unitType', 'status', 'ledger']);
        });
    }

    public function renew(Lease $lease, string $newEndDate, int $staffId): Lease
    {
        $lease->loadMissing('status');
        if (! in_array($lease->status->code, [ApartmentLeaseStatus::ACTIVE, ApartmentLeaseStatus::RENEWED], true)) {
            throw ValidationException::withMessages(['status' => "Cannot renew a {$lease->status->code} lease."]);
        }

        $endDate = Carbon::parse($newEndDate)->startOfDay();
        if ($lease->end_date && $endDate->lte($lease->end_date)) {
            throw ValidationException::withMessages(['end_date' => 'The new end date must be after the current end date.']);
        }

        $this->availability->assertUnitAvailableForLease($lease->unit_id, $lease->start_date, $endDate, excludeLeaseId: $lease->id);

        $from = $lease->end_date?->toDateString();
        $lease->update([
            'end_date' => $endDate,
            'lease_status_id' => Lookup::id(LookupType::APARTMENT_LEASE_STATUS, ApartmentLeaseStatus::RENEWED),
        ]);

        AuditLog::record('apartment_lease.renewed', $lease, ['from' => $from, 'to' => $endDate->toDateString()]);

        return $lease->fresh(['customer', 'unit', 'status']);
    }

    public function terminate(Lease $lease, string $reason, int $staffId): Lease
    {
        $lease->loadMissing(['status', 'unit']);
        if (! in_array($lease->status->code, [ApartmentLeaseStatus::ACTIVE, ApartmentLeaseStatus::RENEWED], true)) {
            throw ValidationException::withMessages(['status' => "Cannot terminate a {$lease->status->code} lease."]);
        }

        $lease->unit->loadMissing('unitType');

        DB::transaction(function () use ($lease, $reason) {
            $lease->update([
                'lease_status_id' => Lookup::id(LookupType::APARTMENT_LEASE_STATUS, ApartmentLeaseStatus::TERMINATED),
                'terminated_at' => now(),
                'termination_reason' => $reason,
            ]);

            // Unit goes DIRTY, not straight to AVAILABLE — a turnover cleaning
            // task is the only path back (ApartmentHousekeepingService::complete()).
            // Final deposit refund/settlement is a manual ledger refund once the
            // last utility bill lands — not automatic.
            $lease->unit->update(['unit_status_id' => Lookup::id(LookupType::APARTMENT_UNIT_STATUS, ApartmentUnitStatus::DIRTY)]);
            HousekeepingTask::create([
                'unit_id' => $lease->unit_id,
                'task_status_id' => Lookup::id(LookupType::TASK_STATUS, TaskStatus::PENDING),
                'checklist' => collect($lease->unit->unitType->cleaning_checklist)
                    ->map(fn ($item) => ['item' => $item, 'done' => false])->values()->all(),
                'lease_id' => $lease->id,
            ]);
        });

        AuditLog::record('apartment_lease.terminated', $lease, ['reason' => $reason]);

        return $lease->fresh(['customer', 'unit', 'status']);
    }
}
