<?php

namespace Database\Seeders\Demo;

use App\Models\Hotel\Ingredient;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\Order;
use App\Models\Hotel\Shift;
use App\Models\User;
use App\Services\Hotel\InventoryService;
use App\Services\Hotel\OrderService;
use App\Services\Hotel\ShiftService;
use App\Support\Lookups\DiningMode;
use App\Support\Lookups\KotStatus;
use App\Support\Lookups\OrderType;
use App\Support\Lookups\PaymentMethod;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * General restaurant floor activity — walk-in/dine-in/takeaway orders,
 * independent of any specific reservation (those room-charge orders are
 * seeded inline with the stay in DemoReservationsSeeder instead). Opens and
 * closes one cash shift per simulated day so payments reconcile properly,
 * and tops up ingredient stock periodically the way real deliveries would,
 * so 45 days of orders don't quietly sell everything out.
 */
class DemoShiftsOrdersSeeder extends Seeder
{
    private ShiftService $shifts;

    private OrderService $orders;

    private InventoryService $inventory;

    /** @var list<int> */
    private array $menuItemIds;

    /** @var list<int> */
    private array $staffIds;

    private Carbon $today;

    public function run(): void
    {
        if (Order::query()->whereNull('room_id')->count() > 5) {
            return; // already seeded
        }

        $this->shifts = app(ShiftService::class);
        $this->orders = app(OrderService::class);
        $this->inventory = app(InventoryService::class);
        $this->menuItemIds = MenuItem::query()->pluck('id')->all();
        $this->staffIds = User::query()->where('status', User::STATUS_ACTIVE)->pluck('id')->all();
        $this->today = Carbon::today();

        $ingredients = Ingredient::all();

        try {
            for ($dayOffset = -45; $dayOffset < 0; $dayOffset++) {
                if ($dayOffset % 3 === 0) {
                    $this->at($this->today->copy()->addDays($dayOffset)->setTime(6, 0));
                    $this->restock($ingredients);
                }

                $this->simulateDay($dayOffset, closeShift: true);
            }

            $this->simulateDay(0, closeShift: false);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function restock(\Illuminate\Support\Collection $ingredients): void
    {
        foreach ($ingredients as $ingredient) {
            $topUp = round($ingredient->stock_qty * fake()->randomFloat(2, 0.12, 0.25), 2);
            if ($topUp <= 0) {
                continue;
            }
            $this->inventory->adjustStock($ingredient, $topUp, 'Periodic supplier delivery', Carbon::now()->addDays(random_int(10, 60))->toDateString());
        }
    }

    private function simulateDay(int $dayOffset, bool $closeShift): void
    {
        $day = $this->today->copy()->addDays($dayOffset);
        $staffId = $this->pick($this->staffIds);

        $this->at($day->copy()->setTime(8, 0));
        try {
            $shift = $this->shifts->openShift($staffId, random_int(50, 150) * 100);
        } catch (\Throwable) {
            return; // staff already has an open shift somehow — skip this day rather than crash the run
        }
        $shift->update(['opened_at' => Carbon::now()]);

        $isToday = $dayOffset === 0;
        $orderCount = $isToday ? random_int(4, 8) : random_int(3, 6);

        for ($i = 0; $i < $orderCount; $i++) {
            $this->at($day->copy()->setTime(random_int(8, 21), random_int(0, 59)));
            $this->placeOrder($staffId, $isToday);
        }

        if ($closeShift) {
            $this->at($day->copy()->setTime(22, 30));
            $cash = $this->shifts->cashForShift($shift->fresh());
            $expected = $shift->opening_cash + $cash['cash_in'] - $cash['cash_out'];
            $closingCash = max(0, $expected + random_int(-500, 500));

            try {
                $this->shifts->closeShift($shift->fresh(), $closingCash, 'End-of-day reconciliation.', User::find($staffId));
            } catch (\Throwable) {
            }
        }
    }

    private function placeOrder(int $staffId, bool $isToday): void
    {
        if ($this->menuItemIds === []) {
            return;
        }

        $items = collect(range(1, random_int(1, 4)))->map(fn () => [
            'menu_item_id' => $this->pick($this->menuItemIds),
            'qty' => random_int(1, 3),
        ])->all();

        try {
            $order = $this->orders->create([
                'type' => OrderType::WALKIN,
                'dining_mode' => random_int(1, 100) <= 65 ? DiningMode::DINE_IN : DiningMode::TAKEAWAY,
                'customer_name' => random_int(1, 100) <= 40 ? fake()->firstName() : null,
                'items' => $items,
            ], $staffId);
        } catch (\Throwable) {
            return; // insufficient stock or similar — skip this one order, not fatal for a bulk seed
        }

        if (random_int(1, 100) <= 5) {
            try {
                $this->orders->void($order, fake()->randomElement(['Guest cancelled', 'Kitchen out of ingredient', 'Order placed in error']));
            } catch (\Throwable) {
            }

            return;
        }

        if (random_int(1, 100) <= 12) {
            try {
                $this->orders->applyDiscount($order, 'PCT', (float) random_int(5, 15), 'Regular customer discount', $staffId);
            } catch (\Throwable) {
            }
        }

        if ($isToday) {
            $liveState = random_int(1, 100);
            if ($liveState <= 25) {
                return; // stays NEW — visible in the "new" KOT column
            }
            if ($liveState <= 45) {
                $this->tryKot($order, KotStatus::PREPARING);

                return;
            }
            if ($liveState <= 60) {
                $this->tryKot($order, KotStatus::PREPARING);
                $this->tryKot($order, KotStatus::READY);

                return;
            }
            $this->tryKot($order, KotStatus::PREPARING);
            $this->tryKot($order, KotStatus::READY);
            $this->tryKot($order, KotStatus::SERVED);
        }

        try {
            $total = $order->fresh()->total;
            $this->orders->settle($order, [['method' => $this->randomPayMethod(), 'amount' => $total]], $staffId);
        } catch (\Throwable) {
        }
    }

    private function tryKot(Order $order, string $status): void
    {
        try {
            $this->orders->updateKotStatus($order, $status);
        } catch (\Throwable) {
        }
    }

    private function randomPayMethod(): string
    {
        return fake()->randomElement([
            PaymentMethod::CASH, PaymentMethod::CASH, PaymentMethod::CASH,
            PaymentMethod::CARD, PaymentMethod::CARD,
            PaymentMethod::LANKAQR,
        ]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function pick(array $ids): mixed
    {
        return $ids[array_rand($ids)];
    }

    private function at(Carbon $moment): void
    {
        Carbon::setTestNow($moment);
    }
}
