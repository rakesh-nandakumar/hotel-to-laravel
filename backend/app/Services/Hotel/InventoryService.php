<?php

namespace App\Services\Hotel;

use App\Models\Hotel\AddOn;
use App\Models\Hotel\Grn;
use App\Models\Hotel\Ingredient;
use App\Models\Hotel\IngredientBatch;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\OrderItem;
use App\Models\Hotel\RecipeItem;
use App\Models\Hotel\StockMovement;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\StockMovementType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Raw-material and product stock: FEFO (first-expiring-first-out) batch
 * tracking, ported from the Node app's lib/pos.ts. `Ingredient::stock_qty` is
 * always the authoritative running total — batches are expiry/cost-tracking
 * detail only. Every batch draw and every return is logged as a signed
 * `stock_movements` row, giving a true cost basis per unit sold and letting a
 * void restock the exact batches it drew from.
 */
class InventoryService
{
    /**
     * @return array{ok: bool, missing: list<string>}
     */
    public function canMake(MenuItem $menuItem, int $portions = 1): array
    {
        $recipe = $menuItem->recipe()->with('ingredient')->get();

        // Recipe-less menu item with direct stock ingredient.
        if ($recipe->isEmpty() && $menuItem->stock_ingredient_id && $menuItem->stockIngredient) {
            $ingredient = $menuItem->stockIngredient;
            $usableStock = $this->usableStock($ingredient);

            if ($usableStock < $portions) {
                if ($usableStock > 0) {
                    $reason = "{$ingredient->name} has only {$usableStock}{$ingredient->unit} usable stock";
                } elseif ($ingredient->stock_qty > 0) {
                    $reason = "{$ingredient->name} is expired";
                } else {
                    $reason = "{$ingredient->name} is out of stock";
                }

                return [
                    'ok' => false,
                    'missing' => [$reason],
                ];
            }

            return ['ok' => true, 'missing' => []];
        }

        $missing = $recipe
            ->filter(function ($recipeItem) use ($portions) {
                $usableStock = $this->usableStock($recipeItem->ingredient);

                return $usableStock < ($recipeItem->qty * $portions);
            })
            ->map(function ($recipeItem) use ($portions) {
                $ingredient = $recipeItem->ingredient;
                $needed = $recipeItem->qty * $portions;
                $usableStock = $this->usableStock($ingredient);

                if ($usableStock <= 0) {
                    if ($ingredient->stock_qty > 0) {
                        return "{$ingredient->name} is expired";
                    }

                    return "{$ingredient->name} is out of stock";
                }

                return "{$ingredient->name} (needs {$needed}{$ingredient->unit}, has {$usableStock}{$ingredient->unit} usable)";
            })
            ->values()
            ->all();

        return [
            'ok' => $missing === [],
            'missing' => $missing,
        ];
    }

    /**
     * Return stock that is actually usable for sale.
     *
     * - Unbatched stock (stock_qty not covered by any batch) is treated as usable
     *   because it has no expiry information.
     * - Batch-tracked stock only counts positive, non-expired batches.
     */
    public function usableStock(Ingredient $ingredient): float
    {
        $today = now()->startOfDay();

        $stockQty = max(0, (float) $ingredient->stock_qty);

        // Total quantity currently represented by batches.
        $batchQty = (float) $ingredient->batches()->sum('qty');

        // Any stock_qty not represented by a batch is unbatched stock.
        // Unbatched stock has no expiry information, so it remains usable.
        $unbatchedQty = max(0, $stockQty - $batchQty);

        // Batch-tracked usable stock: positive, non-expired batches only.
        $usableBatchQty = (float) $ingredient->batches()
            ->where('qty', '>', 0)
            ->where(function ($query) use ($today) {
                $query
                    ->whereNull('expiry_date')
                    ->orWhereDate('expiry_date', '>=', $today);
            })
            ->sum('qty');

        return $unbatchedQty + $usableBatchQty;
    }

    /**
     * Receive/adjust stock with an audit trail. Positive deltas create a new
     * expiry-tracked batch; negative deltas drain existing batches FEFO. An
     * adjustment carries no cost and is not a purchase — it is deliberately
     * distinct from a GRN, which is the only thing that writes a batch cost.
     */
    public function adjustStock(Ingredient $ingredient, float $delta, string $reason, ?string $expiryDate): Ingredient
    {
        if ($ingredient->stock_qty + $delta < 0) {
            throw ValidationException::withMessages(['delta' => 'Stock cannot go negative.']);
        }

        DB::transaction(function () use ($ingredient, $delta, $reason, $expiryDate) {
            $ingredient->increment('stock_qty', $delta);

            if ($delta > 0) {
                $batch = IngredientBatch::create([
                    'ingredient_id' => $ingredient->id,
                    'qty' => $delta,
                    'initial_qty' => $delta,
                    'expiry_date' => $expiryDate,
                    'note' => $reason,
                ]);

                StockMovement::create([
                    'ingredient_id' => $ingredient->id,
                    'ingredient_batch_id' => $batch->id,
                    'movement_type_id' => Lookup::id(LookupType::STOCK_MOVEMENT_TYPE, StockMovementType::ADJUSTMENT),
                    'qty' => $delta,
                    'reference_type' => 'adjustment',
                    'note' => $reason,
                ]);
            } elseif ($delta < 0) {
                $draws = $this->drainBatchesFefo($ingredient->id, -$delta);
                $this->recordConsumptionMovements($ingredient->id, $draws, StockMovementType::ADJUSTMENT, 'adjustment', null, $reason);
            }
        });

        AuditLog::record('ingredient.stock_adjusted', $ingredient, [
            'delta' => $delta, 'reason' => $reason, 'expiry_date' => $expiryDate,
        ]);

        return $ingredient->fresh();
    }

    /**
     * Write off an expired/spoiled batch — deducts stock, mandatory reason.
     *
     * @return float the quantity written off
     */
    public function writeOffBatch(IngredientBatch $batch, string $reason): float
    {
        if ($batch->qty <= 0) {
            throw ValidationException::withMessages(['batch' => 'Batch already empty.']);
        }

        $writtenOff = $batch->qty;

        DB::transaction(function () use ($batch, $reason, $writtenOff) {
            $batch->ingredient->decrement('stock_qty', min($batch->qty, $batch->ingredient->stock_qty));
            $batch->update(['qty' => 0, 'note' => trim(($batch->note ?? '')." [written off: {$reason}]")]);

            StockMovement::create([
                'ingredient_id' => $batch->ingredient_id,
                'ingredient_batch_id' => $batch->id,
                'movement_type_id' => Lookup::id(LookupType::STOCK_MOVEMENT_TYPE, StockMovementType::WRITE_OFF),
                'qty' => -$writtenOff,
                'unit_cost' => $batch->unit_cost,
                'reference_type' => 'write_off',
                'reference_id' => $batch->id,
                'note' => $reason,
            ]);
        });

        AuditLog::record('ingredient.batch_written_off', $batch, [
            'ingredient' => $batch->ingredient->name, 'qty' => $writtenOff, 'reason' => $reason,
        ]);

        return $writtenOff;
    }

    /**
     * Deduct ingredient stock for `portions` of a menu item; direction=-1
     * reverses it (void restock). HARD RULE: stock can never go below zero —
     * insufficient stock throws, rolling back the caller's transaction.
     * Does NOT open its own transaction — must run inside the caller's, so a
     * mid-order failure rolls back the order/items too, not just the stock.
     *
     * A menu item deducts via its recipe when one exists; a recipe-less,
     * unit-stocked product (bottled drink, snack) deducts directly from its
     * `stock_ingredient_id` batch-tracked ingredient — 1 portion = 1 unit.
     *
     * @return list<string> ingredients newly at/below their low-stock threshold
     */
    public function deductStock(MenuItem $menuItem, int $portions, int $direction = 1, ?OrderItem $orderItem = null): array
    {
        $recipe = $menuItem->recipe()->with('ingredient')->get();

        $lowNow = [];
        if ($recipe->isNotEmpty()) {
            foreach ($recipe as $recipeItem) {
                $change = $recipeItem->qty * $portions * $direction;
                $lowNow = [...$lowNow, ...$this->applyIngredientDelta($menuItem, $recipeItem->ingredient, $change, $direction, orderItem: $orderItem)];
            }

            return $lowNow;
        }

        if ($menuItem->stock_ingredient_id && $menuItem->stockIngredient) {
            return $this->applyIngredientDelta($menuItem, $menuItem->stockIngredient, $portions * $direction, $direction, orderItem: $orderItem);
        }

        return $lowNow;
    }

    /**
     * Deduct stock for `portions` of a standalone add-on. Add-ons track
     * inventory through their `stock_ingredient_id` (1 add-on = 1 unit); the
     * same FEFO batch drain and low-stock rules apply. Direction=-1 restocks.
     *
     * @return list<string> ingredients newly at/below their low-stock threshold
     */
    public function deductAddOn(AddOn $addOn, int $portions, int $direction = 1, ?OrderItem $orderItem = null): array
    {
        if (! $addOn->stock_ingredient_id || ! $addOn->stockIngredient) {
            return [];
        }

        return $this->applyIngredientDelta(null, $addOn->stockIngredient, $portions * $direction, $direction, addOn: $addOn, orderItem: $orderItem);
    }

    /**
     * Deduct stock for `qty` units of a directly-sellable Product (an
     * Ingredient with kind=product) — 1 unit sold = 1 unit of stock, the same
     * FEFO drain and InsufficientStockException as a recipe-less menu item.
     * Direction=-1 restocks (void).
     *
     * @return list<string> ingredients newly at/below their low-stock threshold
     */
    public function deductProduct(Ingredient $product, int $qty, int $direction = 1, ?OrderItem $orderItem = null): array
    {
        return $this->applyIngredientDelta(null, $product, $qty * $direction, $direction, product: $product, orderItem: $orderItem);
    }

    /**
     * Post a GRN's lines: one new expiry-tracked batch per line, stock
     * incremented, a grn_receipt movement written per line, and each touched
     * ingredient's `unit_cost` set to its newest received line's cost (latest
     * purchase cost — selling_price is never touched here). Does NOT open its
     * own transaction — must run inside the caller's (GrnService::receive()),
     * which also flips the GRN's status, so a mid-receipt failure rolls back
     * both together.
     */
    public function receiveGrn(Grn $grn): void
    {
        $grn->loadMissing('lines.ingredient');
        $movementTypeId = Lookup::id(LookupType::STOCK_MOVEMENT_TYPE, StockMovementType::GRN_RECEIPT);

        foreach ($grn->lines as $line) {
            $batch = IngredientBatch::create([
                'ingredient_id' => $line->ingredient_id,
                'qty' => $line->qty,
                'initial_qty' => $line->qty,
                'unit_cost' => $line->unit_cost,
                'batch_no' => $line->batch_no,
                'manufactured_at' => $line->manufactured_at,
                'expiry_date' => $line->expiry_date,
                'received_at' => $grn->received_at,
                'grn_line_id' => $line->id,
            ]);

            $line->ingredient->increment('stock_qty', $line->qty);
            $line->ingredient->update(['unit_cost' => $line->unit_cost]);

            StockMovement::create([
                'ingredient_id' => $line->ingredient_id,
                'ingredient_batch_id' => $batch->id,
                'movement_type_id' => $movementTypeId,
                'qty' => $line->qty,
                'unit_cost' => $line->unit_cost,
                'reference_type' => 'grn_line',
                'reference_id' => $line->id,
            ]);
        }
    }

    /**
     * Report menu items that became unavailable because of inventory.
     *
     * IMPORTANT:
     * This method NEVER changes MenuItem::sold_out.
     *
     * `sold_out` is a manual staff/chef override.
     * Inventory availability is calculated live using usableStock().
     *
     * @param  list<int>  $menuItemIds
     * @param  list<int>  $addOnIds
     * @param  list<int>  $productIds
     * @return list<string> names that are currently unavailable
     */
    public function autoSoldOutSweep(
        array $menuItemIds,
        array $addOnIds = [],
        array $productIds = []
    ): array {
        $ingredientIds = RecipeItem::query()
            ->whereIn('menu_item_id', $menuItemIds)
            ->pluck('ingredient_id');

        if ($addOnIds !== []) {
            $ingredientIds = $ingredientIds
                ->merge(
                    AddOn::query()
                        ->whereIn('id', $addOnIds)
                        ->whereNotNull('stock_ingredient_id')
                        ->pluck('stock_ingredient_id')
                );
        }

        $ingredientIds = $ingredientIds
            ->unique()
            ->values();

        $unavailable = [];

        if ($ingredientIds->isNotEmpty()) {

            /*
             * Recipe menu items.
             *
             * We intentionally DO NOT filter by sold_out here.
             * Inventory status and manual sold-out status are separate.
             */
            $affected = RecipeItem::query()
                ->whereIn('ingredient_id', $ingredientIds)
                ->whereHas('menuItem', function ($q) {
                    $q->where('active', true);
                })
                ->with([
                    'ingredient:id,stock_qty,active',
                    'menuItem:id,name,sold_out',
                ])
                ->get();

            /*
             * Direct-stock menu items.
             */
            $direct = MenuItem::query()
                ->where('active', true)
                ->whereIn('stock_ingredient_id', $ingredientIds)
                ->with([
                    'stockIngredient:id,stock_qty,active',
                ])
                ->get();

            foreach ($affected as $recipeItem) {
                if (! $recipeItem->ingredient) {
                    continue;
                }

                $usableStock = $this->usableStock($recipeItem->ingredient);

                if ($usableStock < $recipeItem->qty) {
                    $unavailable[$recipeItem->menu_item_id] =
                        $recipeItem->menuItem->name;
                }
            }

            foreach ($direct as $item) {
                if (! $item->stockIngredient) {
                    continue;
                }

                if ($this->usableStock($item->stockIngredient) < 1) {
                    $unavailable[$item->id] = $item->name;
                }
            }
        }

        /*
         * Products do not have a MenuItem::sold_out flag.
         * Their availability is purely based on usable stock.
         */
        if ($productIds !== []) {
            $products = Ingredient::query()
                ->whereIn('id', $productIds)
                ->where('active', true)
                ->get();

            foreach ($products as $product) {
                if ($this->usableStock($product) <= 0) {
                    $unavailable[] = $product->name;
                }
            }
        }

        /*
         * CRITICAL:
         *
         * DO NOT update menu_items.sold_out here.
         *
         * Inventory shortage/expiry is runtime availability only.
         */
        return array_values(array_unique($unavailable));
    }

    /**
     * Write off every batch that has passed its expiry date and still
     * carries stock — the scheduled counterpart to the manual "Write off"
     * button, so expired stock never lingers as sellable/usable inventory
     * waiting on a human to notice the expiry alert. Idempotent: an
     * already-written-off batch has qty=0 and is excluded by the query, so
     * this tolerates being run more than once (e.g. catch-up after
     * downtime).
     *
     * @return array{written_off_batches: int, newly_unavailable: list<string>}
     */
    public function autoWriteOffExpiredBatches(): array
    {
        $batches = IngredientBatch::query()->expired()->where('qty', '>', 0)->get();

        if ($batches->isEmpty()) {
            return ['written_off_batches' => 0, 'newly_unavailable' => []];
        }

        $ingredientIds = $batches->pluck('ingredient_id')->unique()->values();

        foreach ($batches as $batch) {
            $this->writeOffBatch($batch, 'Automatic write-off — passed expiry date '.$batch->expiry_date->toDateString().'.');
        }

        // Best-effort freshness for the cached availability badges.
        // The live checks in unavailableMenuItemIds()/sellableQty()/canMake()
        // are what actually guarantee correctness for listings and sales;
        // this just keeps the realtime broadcast informed.
        $menuItemIds = RecipeItem::query()->whereIn('ingredient_id', $ingredientIds)->pluck('menu_item_id')->unique()->values()->all();
        $productIds = Ingredient::query()->whereIn('id', $ingredientIds)->products()->pluck('id')->all();
        $newlyUnavailable = $this->autoSoldOutSweep($menuItemIds, [], $productIds);

        return ['written_off_batches' => $batches->count(), 'newly_unavailable' => $newlyUnavailable];
    }

    /**
     * Live availability check for a set of menu items, batched to a
     * constant number of queries regardless of how many items are passed —
     * safe to call from a listing endpoint (POS grid, search), not just
     * per-order. Deliberately ignores the persisted `sold_out` flag, which
     * is only a manual override; this recomputes from live stock/expiry so a
     * batch that expired since the last sweep can never still be listed as
     * orderable.
     *
     * @param  Collection<int, MenuItem>  $menuItems  must have `recipe.ingredient` and `stockIngredient` eager-loaded
     * @return Collection<int, int> the ids of items that can NOT currently be made
     */
    public function unavailableMenuItemIds(Collection $menuItems): Collection
    {
        $ingredients = $menuItems
            ->flatMap(fn (MenuItem $item) => $item->recipe->isNotEmpty()
                ? $item->recipe->pluck('ingredient')
                : ($item->stockIngredient ? [$item->stockIngredient] : []))
            ->unique('id');

        if ($ingredients->isEmpty()) {
            return collect();
        }

        $expiredByIngredient = IngredientBatch::query()
            ->selectRaw('ingredient_id, SUM(qty) as total')
            ->whereIn('ingredient_id', $ingredients->pluck('id'))
            ->where('qty', '>', 0)
            ->expired()
            ->groupBy('ingredient_id')
            ->pluck('total', 'ingredient_id');

        $sellableByIngredient = $ingredients->mapWithKeys(function (Ingredient $ingredient) use ($expiredByIngredient) {
            $qty = $ingredient->active
                ? max(0.0, $ingredient->stock_qty - (float) ($expiredByIngredient[$ingredient->id] ?? 0))
                : 0.0;

            return [$ingredient->id => $qty];
        });

        return $menuItems
            ->filter(function (MenuItem $item) use ($sellableByIngredient) {
                $ok = $item->recipe->isNotEmpty()
                    ? $item->recipe->every(fn (RecipeItem $r) => ($sellableByIngredient[$r->ingredient_id] ?? 0) >= $r->qty)
                    : (! $item->stock_ingredient_id || ($sellableByIngredient[$item->stock_ingredient_id] ?? 0) >= 1);

                return ! $ok;
            })
            ->pluck('id');
    }

    /**
     * Apply a signed delta to a single ingredient: check sufficiency on
     * deduction, decrement the authoritative total, drain/restock the FEFO
     * batches, and report whether it just crossed into low-stock territory.
     *
     * @return list<string> low-stock labels crossed on this deduction
     */
    private function applyIngredientDelta(
        ?MenuItem $menuItem,
        Ingredient $ingredient,
        float $change,
        int $direction,
        ?AddOn $addOn = null,
        ?Ingredient $product = null,
        ?OrderItem $orderItem = null,
    ): array {
        if ($direction === 1) {
            // Use usable (non-expired) stock so an expired batch can never
            // be sold even if stock_qty still shows a positive total.
            $available = $this->usableStock($ingredient);
            if ($available < $change) {
                $label = $menuItem?->name ?? $addOn?->name ?? $product?->name ?? 'Item';

                throw new InsufficientStockException(
                    $menuItem?->id,
                    "Not enough {$ingredient->name} in stock ({$available}{$ingredient->unit} available, needs {$change}{$ingredient->unit}) — \"{$label}\"",
                    $addOn?->id,
                    $product?->id,
                );
            }
        }

        $ingredient->decrement('stock_qty', $change);
        $ingredient->refresh();

        $referenceType = $orderItem ? 'order_item' : null;
        $referenceId = $orderItem?->id;

        if ($direction === 1) {
            // A sale must never draw down an already-expired batch, even
            // though usableStock() above already excluded expired qty from
            // the sufficiency check — this is the belt-and-braces guard at
            // the point stock is actually physically consumed.
            $draws = $this->drainBatchesFefo($ingredient->id, $change, excludeExpired: true);
            $this->recordConsumptionMovements($ingredient->id, $draws, StockMovementType::SALE, $referenceType, $referenceId);
        } else {
            $this->restockBatches($ingredient->id, $referenceType, $referenceId);
        }

        if ($direction === 1
            && $ingredient->stock_qty <= $ingredient->low_stock_threshold
            && $ingredient->stock_qty + $change > $ingredient->low_stock_threshold) {
            return ["{$ingredient->name} ({$ingredient->stock_qty}{$ingredient->unit} left)"];
        }

        return [];
    }

    /**
     * Reverse batch draws for a given reference (e.g. a voided order item) by
     * looking up its `sale` movements and returning each one to the exact
     * batch it drew from, writing a `sale_reversal` movement per batch.
     */
    private function restockBatches(int $ingredientId, ?string $referenceType, ?int $referenceId): void
    {
        $saleMovements = StockMovement::query()
            ->where('ingredient_id', $ingredientId)
            ->where('movement_type_id', Lookup::id(LookupType::STOCK_MOVEMENT_TYPE, StockMovementType::SALE))
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->get();

        if ($saleMovements->isEmpty()) {
            return;
        }

        $reversalTypeId = Lookup::id(LookupType::STOCK_MOVEMENT_TYPE, StockMovementType::SALE_REVERSAL);

        foreach ($saleMovements as $movement) {
            if ($movement->ingredient_batch_id) {
                IngredientBatch::query()->where('id', $movement->ingredient_batch_id)->increment('qty', -$movement->qty);
            }

            StockMovement::create([
                'ingredient_id' => $ingredientId,
                'ingredient_batch_id' => $movement->ingredient_batch_id,
                'movement_type_id' => $reversalTypeId,
                'qty' => -$movement->qty,
                'unit_cost' => $movement->unit_cost,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);
        }
    }

    /**
     * Dated batches drain earliest-expiry-first (tie-broken by received_at);
     * undated batches drain FIFO by received_at, but only after every dated
     * batch is exhausted — MySQL sorts NULL expiry_date first on a plain
     * ASC order, which would otherwise drain undated stock before expiring
     * stock. The CASE expression runs on both MySQL (production) and SQLite
     * (tests).
     *
     * `$excludeExpired` is true for an actual sale (stock must never be sold
     * past its expiry date) and false for a manual adjustment write-down,
     * where draining the already-expired batch first is the desired
     * behaviour (that's usually exactly the stock the correction is for —
     * a specific already-expired batch is written off via writeOffBatch()
     * instead, when that's the intent).
     *
     * @return list<array{batch_id: int, qty: float, unit_cost: ?int}> the draws taken, oldest-drawn first
     */
    private function drainBatchesFefo(int $ingredientId, float $qty, bool $excludeExpired = false): array
    {
        $remaining = $qty;
        $draws = [];

        $batches = IngredientBatch::query()
            ->where('ingredient_id', $ingredientId)
            ->where('qty', '>', 0)
            ->when($excludeExpired, fn ($q) => $q->notExpired())
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($batch->qty, $remaining);
            $batch->update(['qty' => $batch->qty - $take]);
            $draws[] = ['batch_id' => $batch->id, 'qty' => $take, 'unit_cost' => $batch->unit_cost];
            $remaining -= $take;
        }

        return $draws;
    }

    /**
     * Write one signed "out" stock_movement per batch draw. Adjustments
     * deliberately drop the batch's cost (`unit_cost` => null) — an
     * adjustment carries no cost, unlike a real sale.
     *
     * @param  list<array{batch_id: int, qty: float, unit_cost: ?int}>  $draws
     */
    private function recordConsumptionMovements(
        int $ingredientId,
        array $draws,
        string $movementType,
        ?string $referenceType,
        ?int $referenceId,
        ?string $note = null,
    ): void {
        if ($draws === []) {
            return;
        }

        $movementTypeId = Lookup::id(LookupType::STOCK_MOVEMENT_TYPE, $movementType);

        foreach ($draws as $draw) {
            StockMovement::create([
                'ingredient_id' => $ingredientId,
                'ingredient_batch_id' => $draw['batch_id'],
                'movement_type_id' => $movementTypeId,
                'qty' => -$draw['qty'],
                'unit_cost' => $movementType === StockMovementType::ADJUSTMENT ? null : $draw['unit_cost'],
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'note' => $note,
            ]);
        }
    }
}