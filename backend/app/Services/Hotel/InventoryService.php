<?php

namespace App\Services\Hotel;

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
     * @return list<string> ingredients newly at/below their low-stock threshold
     */
    public function deductStock(MenuItem $menuItem, int $portions, int $direction = 1): array
    {
        $recipe = $menuItem->recipe()->with('ingredient')->get();
        $lowNow = [];

        foreach ($recipe as $recipeItem) {
            $ingredient = $recipeItem->ingredient;
            $change = $recipeItem->qty * $portions * $direction;

            if ($direction === 1 && $ingredient->stock_qty < $change) {
                throw new InsufficientStockException(
                    $menuItem->id,
                    "Not enough {$ingredient->name} in stock ({$ingredient->stock_qty}{$ingredient->unit} left, needs {$change}{$ingredient->unit})",
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
                $lowNow[] = "{$ingredient->name} ({$ingredient->stock_qty}{$ingredient->unit} left)";
            }
        }

        return $lowNow;
    }

    /**
     * After deductions: auto-mark as sold out any active item sharing these
     * ingredients that can no longer make a single portion.
     *
     * @param  list<int>  $menuItemIds
     * @return list<string> names marked sold out
     */
    public function autoSoldOutSweep(array $menuItemIds): array
    {
        $ingredientIds = RecipeItem::query()->whereIn('menu_item_id', $menuItemIds)->pluck('ingredient_id')->unique()->values();
        if ($ingredientIds->isEmpty()) {
            return [];
        }

        $affected = RecipeItem::query()
            ->whereHas('menuItem', fn ($q) => $q->where('active', true)->where('sold_out', false)
                ->whereHas('recipe', fn ($q2) => $q2->whereIn('ingredient_id', $ingredientIds)))
            ->with(['ingredient:id,stock_qty', 'menuItem:id,name'])
            ->get();

        $short = [];
        foreach ($affected as $recipeItem) {
            if ($recipeItem->ingredient->stock_qty < $recipeItem->qty) {
                $short[$recipeItem->menu_item_id] = $recipeItem->menuItem->name;
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
