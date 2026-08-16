<?php

namespace App\Services\Hotel;

use App\Models\Hotel\AddOn;
use App\Models\Hotel\Ingredient;
use App\Models\Hotel\IngredientBatch;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\RecipeItem;
use App\Services\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Raw-material stock: FEFO (first-expiring-first-out) batch tracking, ported
 * from the Node app's lib/pos.ts. `Ingredient::stock_qty` is always the
 * authoritative running total — batches are expiry-tracking detail only.
 */
class InventoryService
{
    /**
     * @return array{ok: bool, missing: list<string>}
     */
    public function canMake(MenuItem $menuItem, int $portions = 1): array
    {
        $recipe = $menuItem->recipe()->with('ingredient')->get();

        if ($recipe->isEmpty() && $menuItem->stock_ingredient_id && $menuItem->stockIngredient) {
            $ingredient = $menuItem->stockIngredient;

            return $ingredient->stock_qty < $portions
                ? ['ok' => false, 'missing' => ["{$ingredient->name} (needs {$portions}{$ingredient->unit}, has {$ingredient->stock_qty}{$ingredient->unit})"]]
                : ['ok' => true, 'missing' => []];
        }

        $missing = $recipe
            ->filter(fn ($r) => $r->ingredient->stock_qty < $r->qty * $portions)
            ->map(fn ($r) => "{$r->ingredient->name} (needs ".($r->qty * $portions)."{$r->ingredient->unit}, has {$r->ingredient->stock_qty}{$r->ingredient->unit})")
            ->values()
            ->all();

        return ['ok' => $missing === [], 'missing' => $missing];
    }

    /**
     * Receive/adjust stock with an audit trail. Positive deltas create a new
     * expiry-tracked batch; negative deltas drain existing batches FEFO.
     */
    public function adjustStock(Ingredient $ingredient, float $delta, string $reason, ?string $expiryDate): Ingredient
    {
        if ($ingredient->stock_qty + $delta < 0) {
            throw ValidationException::withMessages(['delta' => 'Stock cannot go negative.']);
        }

        DB::transaction(function () use ($ingredient, $delta, $reason, $expiryDate) {
            $ingredient->increment('stock_qty', $delta);

            if ($delta > 0) {
                IngredientBatch::create([
                    'ingredient_id' => $ingredient->id,
                    'qty' => $delta,
                    'initial_qty' => $delta,
                    'expiry_date' => $expiryDate,
                    'note' => $reason,
                ]);
            } elseif ($delta < 0) {
                $this->drainBatchesFefo($ingredient->id, -$delta);
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

        DB::transaction(function () use ($batch, $reason) {
            $batch->ingredient->decrement('stock_qty', min($batch->qty, $batch->ingredient->stock_qty));
            $batch->update(['qty' => 0, 'note' => trim(($batch->note ?? '')." [written off: {$reason}]")]);
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
    public function deductStock(MenuItem $menuItem, int $portions, int $direction = 1): array
    {
        $recipe = $menuItem->recipe()->with('ingredient')->get();

        $lowNow = [];
        if ($recipe->isNotEmpty()) {
            foreach ($recipe as $recipeItem) {
                $change = $recipeItem->qty * $portions * $direction;
                $lowNow = [...$lowNow, ...$this->applyIngredientDelta($menuItem, $recipeItem->ingredient, $change, $direction)];
            }

            return $lowNow;
        }

        if ($menuItem->stock_ingredient_id && $menuItem->stockIngredient) {
            return $this->applyIngredientDelta($menuItem, $menuItem->stockIngredient, $portions * $direction, $direction);
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
    public function deductAddOn(AddOn $addOn, int $portions, int $direction = 1): array
    {
        if (! $addOn->stock_ingredient_id || ! $addOn->stockIngredient) {
            return [];
        }

        return $this->applyIngredientDelta(null, $addOn->stockIngredient, $portions * $direction, $direction, $addOn);
    }

    /**
     * Apply a signed delta to a single ingredient: check sufficiency on
     * deduction, decrement the authoritative total, drain/restock the FEFO
     * batches, and report whether it just crossed into low-stock territory.
     *
     * @return list<string> low-stock labels crossed on this deduction
     */
    private function applyIngredientDelta(?MenuItem $menuItem, Ingredient $ingredient, float $change, int $direction, ?AddOn $addOn = null): array
    {
        if ($direction === 1 && $ingredient->stock_qty < $change) {
            $label = $menuItem ? $menuItem->name : ($addOn?->name ?? 'Item');
            $owner = $menuItem ? $menuItem->id : ($addOn?->id ?? 0);

            throw new InsufficientStockException(
                $owner,
                "Not enough {$ingredient->name} in stock ({$ingredient->stock_qty}{$ingredient->unit} left, needs {$change}{$ingredient->unit}) — \"{$label}\"",
            );
        }

        $ingredient->decrement('stock_qty', $change);
        $ingredient->refresh();

        if ($direction === 1) {
            $this->drainBatchesFefo($ingredient->id, $change);
        } else {
            $this->restockBatches($ingredient->id, -$change);
        }

        if ($direction === 1
            && $ingredient->stock_qty <= $ingredient->low_stock_threshold
            && $ingredient->stock_qty + $change > $ingredient->low_stock_threshold) {
            return ["{$ingredient->name} ({$ingredient->stock_qty}{$ingredient->unit} left)"];
        }

        return [];
    }

    /**
     * After deductions: auto-mark as sold out any active item sharing these
     * ingredients that can no longer make a single portion — both recipe-based
     * items and unit-stocked (direct-ingredient) items.
     *
     * @param  list<int>  $menuItemIds
     * @param  list<int>  $addOnIds
     * @return list<string> names marked sold out
     */
    public function autoSoldOutSweep(array $menuItemIds, array $addOnIds = []): array
    {
        $ingredientIds = RecipeItem::query()->whereIn('menu_item_id', $menuItemIds)->pluck('ingredient_id');
        if ($addOnIds !== []) {
            $ingredientIds = $ingredientIds->merge(AddOn::query()->whereIn('id', $addOnIds)->whereNotNull('stock_ingredient_id')->pluck('stock_ingredient_id'));
        }
        $ingredientIds = $ingredientIds->unique()->values();

        if ($ingredientIds->isEmpty()) {
            return [];
        }

        $affected = RecipeItem::query()
            ->whereHas('menuItem', fn ($q) => $q->where('active', true)->where('sold_out', false)
                ->whereHas('recipe', fn ($q2) => $q2->whereIn('ingredient_id', $ingredientIds)))
            ->with(['ingredient:id,stock_qty', 'menuItem:id,name'])
            ->get();

        // Unit-stocked products (no recipe) — can't fulfil a single unit any more.
        $direct = MenuItem::query()
            ->where('active', true)->where('sold_out', false)
            ->whereIn('stock_ingredient_id', $ingredientIds)
            ->with('stockIngredient:id,stock_qty')
            ->get();

        $short = [];
        foreach ($affected as $recipeItem) {
            if ($recipeItem->ingredient->stock_qty < $recipeItem->qty) {
                $short[$recipeItem->menu_item_id] = $recipeItem->menuItem->name;
            }
        }
        foreach ($direct as $item) {
            if ($item->stockIngredient && $item->stockIngredient->stock_qty < 1) {
                $short[$item->id] = $item->name;
            }
        }

        if ($short === []) {
            return [];
        }

        MenuItem::query()->whereIn('id', array_keys($short))->update(['sold_out' => true]);

        return array_values($short);
    }

    private function restockBatches(int $ingredientId, float $qty): void
    {
        $latest = IngredientBatch::query()->where('ingredient_id', $ingredientId)->orderByDesc('received_at')->first();

        if ($latest) {
            $latest->increment('qty', $qty);
        }
    }

    private function drainBatchesFefo(int $ingredientId, float $qty): void
    {
        $remaining = $qty;

        $batches = IngredientBatch::query()
            ->where('ingredient_id', $ingredientId)
            ->where('qty', '>', 0)
            ->orderBy('expiry_date')
            ->orderBy('received_at')
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($batch->qty, $remaining);
            $batch->update(['qty' => $batch->qty - $take]);
            $remaining -= $take;
        }
    }
}
