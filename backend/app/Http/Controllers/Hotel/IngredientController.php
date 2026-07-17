<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\AdjustIngredientStockRequest;
use App\Http\Requests\Hotel\StoreIngredientRequest;
use App\Http\Requests\Hotel\UpdateIngredientRequest;
use App\Http\Requests\Hotel\WriteOffIngredientBatchRequest;
use App\Models\Hotel\Ingredient;
use App\Models\Hotel\IngredientBatch;
use App\Services\AuditLog;
use App\Services\Hotel\InventoryService;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class IngredientController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function index(Request $request): JsonResponse
    {
        $today = now()->startOfDay();

        $all = Ingredient::query()
            ->with([
                'batches' => fn ($q) => $q->where('qty', '>', 0)->whereNotNull('expiry_date')->orderBy('expiry_date')->limit(5),
                'recipeItems.menuItem:id,name',
            ])
            ->orderBy('name')
            ->get()
            ->map(function (Ingredient $ingredient) use ($today) {
                $row = $ingredient->toArray();
                unset($row['recipe_items']);
                $row['used_in'] = $ingredient->recipeItems->pluck('menuItem.name')->unique()->values();
                $row['low'] = $ingredient->isLow();
                $row['next_expiry'] = $ingredient->batches->first()?->expiry_date;
                $row['has_expired'] = $ingredient->batches->contains(fn (IngredientBatch $b) => $b->expiry_date && $b->expiry_date->lt($today));

                return $row;
            });

        if ($request->has('page')) {
            $q = strtolower($request->string('q')->toString());
            $filter = $request->string('filter', 'ALL')->toString();

            $filtered = match ($filter) {
                'LOW' => $all->where('low', true),
                'EXPIRING' => $all->filter(fn ($r) => $r['next_expiry'] || $r['has_expired']),
                'UNTRACKED' => $all->filter(fn ($r) => ! $r['next_expiry']),
                default => $all,
            };
            if ($q !== '') {
                $filtered = $filtered->filter(fn ($r) => str_contains(strtolower($r['name']), $q));
            }

            $page = max(1, $request->integer('page', 1));
            $pageSize = min(200, max(1, $request->integer('page_size', 25)));

            return response()->json([
                'ingredients' => $filtered->values()->slice(($page - 1) * $pageSize, $pageSize)->values(),
                'total' => $filtered->count(),
                'page' => $page,
                'page_size' => $pageSize,
                'counts' => [
                    'total' => $all->count(),
                    'low' => $all->where('low', true)->count(),
                    'expiry_tracked' => $all->filter(fn ($r) => $r['next_expiry'] || $r['has_expired'])->count(),
                    'untracked' => $all->filter(fn ($r) => ! $r['next_expiry'])->count(),
                ],
            ]);
        }

        return response()->json(['ingredients' => $all->values()]);
    }

    public function store(StoreIngredientRequest $request): JsonResponse
    {
        $ingredient = Ingredient::create($request->validated());

        AuditLog::record('ingredient.created', $ingredient, ['name' => $ingredient->name]);

        return response()->json(['message' => "\"{$ingredient->name}\" created.", 'ingredient' => $ingredient], 201);
    }

    public function update(UpdateIngredientRequest $request, Ingredient $ingredient): JsonResponse
    {
        $ingredient->update($request->validated());

        AuditLog::record('ingredient.updated', $ingredient, ['name' => $ingredient->name]);

        return response()->json(['message' => 'Ingredient updated.', 'ingredient' => $ingredient]);
    }

    /** Blocked while any menu recipe uses it — remove it from those recipes first. */
    public function destroy(Ingredient $ingredient): JsonResponse
    {
        $usedIn = $ingredient->recipeItems()->with('menuItem:id,name')->get()->pluck('menuItem.name')->unique()->values();

        if ($usedIn->isNotEmpty()) {
            $shown = $usedIn->take(5)->implode(', ').($usedIn->count() > 5 ? '…' : '');
            throw ValidationException::withMessages([
                'ingredient' => "Cannot remove — used in {$usedIn->count()} recipe(s): {$shown}. Edit those menu items first.",
            ]);
        }

        $name = $ingredient->name;
        $stockAtDeletion = $ingredient->stock_qty;
        $ingredient->delete();

        AuditLog::record('ingredient.deleted', $ingredient, ['name' => $name, 'stock_at_deletion' => $stockAtDeletion]);

        return response()->json(['message' => "\"{$name}\" removed."]);
    }

    /** Stock receive/adjust with an audit trail. Positive deltas create an expiry-tracked batch. */
    public function adjustStock(AdjustIngredientStockRequest $request, Ingredient $ingredient): JsonResponse
    {
        $data = $request->validated();

        $updated = $this->inventory->adjustStock(
            $ingredient, (float) $data['delta'], $data['reason'], $data['expiry_date'] ?? null,
        );

        return response()->json(['message' => 'Stock adjusted.', 'ingredient' => $updated]);
    }

    /** Expiry board: batches expired or expiring within the warn window (Setting). */
    public function expiry(): JsonResponse
    {
        $warnDays = (int) Settings::num('inventory.expiry_warn_days', 3);
        $today = now()->startOfDay();
        $cutoff = $today->copy()->addDays($warnDays);

        $batches = IngredientBatch::query()
            ->where('qty', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $cutoff)
            ->with('ingredient:id,name,unit')
            ->orderBy('expiry_date')
            ->get()
            ->map(function (IngredientBatch $batch) use ($today) {
                $row = $batch->toArray();
                $row['days_left'] = (int) ceil(($batch->expiry_date->copy()->startOfDay()->timestamp - $today->timestamp) / 86400);
                $row['expired'] = $batch->expiry_date->lt($today);

                return $row;
            });

        return response()->json(['batches' => $batches]);
    }

    /** Write off an expired/spoiled batch — deducts stock, mandatory reason. */
    public function writeOff(WriteOffIngredientBatchRequest $request, IngredientBatch $batch): JsonResponse
    {
        $unit = $batch->ingredient->unit;
        $writtenOff = $this->inventory->writeOffBatch($batch, $request->validated('reason'));

        return response()->json(['ok' => true, 'written_off' => $writtenOff, 'unit' => $unit]);
    }
}
