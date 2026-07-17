<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\AdjustGuestLoyaltyRequest;
use App\Http\Requests\Hotel\StoreGuestRequest;
use App\Http\Requests\Hotel\UpdateGuestRequest;
use App\Models\Hotel\Guest;
use App\Services\AuditLog;
use App\Support\Lookups\ReservationStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Guest::query();

        if ($term = $request->string('q')->toString()) {
            $query->search($term);
        }

        $query->orderBy(...match ($request->string('sort')->toString()) {
            'spend' => ['lifetime_spend', 'desc'],
            'points' => ['loyalty_points', 'desc'],
            'name' => ['name', 'asc'],
            default => ['created_at', 'desc'],
        });

        if (! $request->has('page')) {
            return response()->json(['guests' => $query->limit(100)->get()]);
        }

        $stats = (clone $query)->reorder()->selectRaw('SUM(lifetime_spend) as lifetime_spend, SUM(loyalty_points) as loyalty_points')->first();

        return response()->json([
            'guests' => $query->paginate($request->integer('page_size', 25))->withQueryString(),
            'stats' => [
                'lifetime_spend' => (int) ($stats->lifetime_spend ?? 0),
                'loyalty_points' => (int) ($stats->loyalty_points ?? 0),
            ],
        ]);
    }

    public function show(Guest $guest): JsonResponse
    {
        $guest->load([
            'loyaltyTransactions' => fn ($q) => $q->latest('created_at')->limit(50)->with('staff:id,name'),
            'reservations' => fn ($q) => $q->latest('check_in')->limit(20)->with(['status', 'rooms.room:id,number']),
        ]);

        return response()->json([
            'guest' => $guest,
            'total_stays' => $guest->reservations()->statusCode(ReservationStatus::CHECKED_OUT)->count(),
        ]);
    }

    public function store(StoreGuestRequest $request): JsonResponse
    {
        $guest = Guest::create($request->validated());

        AuditLog::record('guest.created', $guest, ['name' => $guest->name]);

        return response()->json(['message' => "Guest \"{$guest->name}\" created.", 'guest' => $guest], 201);
    }

    public function update(UpdateGuestRequest $request, Guest $guest): JsonResponse
    {
        $guest->update($request->validated());

        AuditLog::record('guest.updated', $guest, ['name' => $guest->name]);

        return response()->json(['message' => 'Guest updated.', 'guest' => $guest]);
    }

    /**
     * Manual point adjustment. Guarded so a guest's balance can never go
     * negative, and always logged to the ledger inside the same transaction
     * as the denormalized balance update (ported from Node's guests.ts).
     */
    public function adjustLoyalty(AdjustGuestLoyaltyRequest $request, Guest $guest): JsonResponse
    {
        $points = $request->integer('points');
        $reason = $request->string('reason')->toString();

        if ($guest->loyalty_points + $points < 0) {
            throw ValidationException::withMessages([
                'points' => 'Adjustment would make points negative.',
            ]);
        }

        DB::transaction(function () use ($guest, $points, $reason, $request) {
            $guest->increment('loyalty_points', $points);

            $guest->loyaltyTransactions()->create([
                'points' => $points,
                'reason' => $reason,
                'staff_id' => $request->user()->id,
            ]);
        });

        AuditLog::record('guest.loyalty_adjusted', $guest, ['points' => $points, 'reason' => $reason]);

        return response()->json(['message' => 'Loyalty points adjusted.', 'guest' => $guest->fresh()]);
    }
}
