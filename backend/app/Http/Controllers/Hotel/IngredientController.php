<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\AdjustIngredientStockRequest;
use App\Http\Requests\Hotel\StoreIngredientRequest;
use App\Http\Requests\Hotel\UpdateIngredientRequest;
use App\Http\Requests\Hotel\WriteOffIngredientBatchRequest;
use App\Models\Hotel\Ingredient;
use App\Models\Hotel\IngredientBatch;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Services\Hotel\InventoryService;
use App\Services\Settings;
use App\Support\Lookups\InventoryKind;
use App\Support\Lookups\LookupType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class IngredientController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function index(Request $request): JsonResponse
    {
        $today = now()->startOfDay();

        $query = Ingredient::query()
            ->with([
                'kind:id,code',
                'batches' => fn ($q) => $q->where('qty', '>', 0)->whereNotNull('expiry_date')->orderBy('expiry_date')->limit(5),
                'recipeItems.menuItem:id,name',
            ])
            ->orderBy('name');

        $kind = $request->string('kind')->toString();
        if ($kind === InventoryKind::INGREDIENT) {
            $query->ingredients();
        } elseif ($kind === InventoryKind::PRODUCT) {
            $query->products();
        }

        $all = $query->get()
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
        $data = $request->validated();
        $data['inventory_kind_id'] = Lookup::id(LookupType::INVENTORY_KIND, $data['kind']);
        unset($data['kind']);

        $ingredient = Ingredient::create($data);

        AuditLog::record('ingredient.created', $ingredient, ['name' => $ingredient->name]);

        return response()->json(['message' => "\"{$ingredient->name}\" created.", 'ingredient' => $ingredient], 201);
    }

    public function update(UpdateIngredientRequest $request, Ingredient $ingredient): JsonResponse
    {
        $data = $request->validated();
        if (array_key_exists('kind', $data)) {
            $data['inventory_kind_id'] = Lookup::id(LookupType::INVENTORY_KIND, $data['kind']);
            unset($data['kind']);
        }

        $ingredient->update($data);

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

    /** Lightweight product search for POS — returns only sellable products with stock. */
    public function searchProducts(Request $request): JsonResponse
    {
        $q = $request->string('q')->toString();
        $limit = min(50, max(1, $request->integer('limit', 20)));

        $query = Ingredient::query()
            ->products()
            ->active()
            ->where('stock_qty', '>', 0)
            ->whereNotNull('selling_price')
            ->where('selling_price', '>', 0)
            ->select('id', 'name', 'selling_price', 'stock_qty', 'image', 'menu_category_id', 'unit')
            ->orderBy('name');

        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "%{$q}%")
                    ->orWhere('id', 'like', "%{$q}%");
            });
        }

        $products = $query->limit($limit)->get()->map(function (Ingredient $p) {
            return [
                'id' => $p->id,
                'name' => $p->name,
                'selling_price' => $p->selling_price,
                'stock_qty' => $p->stock_qty,
                'image' => $p->image,
                'unit' => $p->unit,
            ];
        });

return response()->json(['products' => $products]);
    }

    /** Dedicated barcode scan endpoint — fast path for POS scanners. */
    public function scanBarcode(Request $request): JsonResponse
    {
        $code = $request->string('code')->toString();
        if ($code === '') {
            return response()->json(['error' => 'Barcode required'], 400);
        }

        // Try exact match on item_no first (for products with barcode)
        $product = Ingredient::query()
            ->products()
            ->active()
            ->where('stock_qty', '>', 0)
            ->where(function ($q) use ($code) {
                $q->where('item_no', (int) $code)
                    ->orWhere('id', (int) $code)
                    ->orWhere('name', 'like', "%{$code}%");
            })
            ->select('id', 'name', 'selling_price', 'stock_qty', 'image', 'menu_category_id', 'unit')
            ->first();

        if ($product) {
            return response()->json([
                'found' => true,
                'type' => 'product',
                'data' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'selling_price' => $product->selling_price,
                    'stock_qty' => $product->stock_qty,
                    'image' => $product->image,
                    'unit' => $product->unit,
                ],
            ]);
        }

        // Fallback to menu item search by item_no
        $menuItem = \App\Models\Hotel\MenuItem::query()
            ->where('active', true)
            ->where('sold_out', false)
            ->where('item_no', (int) $code)
            ->select('id', 'name', 'price', 'item_no', 'description', 'image', 'menu_category_id', 'stock_ingredient_id')
            ->with([
                'modifierGroups' => fn ($g) => $g->orderBy('sort_order')->with(['modifiers' => fn ($m) => $m->where('active', true)->orderBy('sort_order')]),
                'linkedAddOns' => fn ($a) => $a->active()->orderBy('sort_order'),
                'categoryAddOns' => fn ($a) => $a->active()->orderBy('sort_order'),
            ])
            ->first();

        if ($menuItem) {
            $addOns = $menuItem->linkedAddOns->concat($menuItem->categoryAddOns)->unique('id')->values();
            return response()->json([
                'found' => true,
                'type' => 'menu_item',
                'data' => [
                    'id' => $menuItem->id,
                    'name' => $menuItem->name,
                    'price' => $menuItem->price,
                    'item_no' => $menuItem->item_no,
                    'description' => $menuItem->description,
                    'image' => $menuItem->image,
                    'menu_category_id' => $menuItem->menu_category_id,
                    'stock_ingredient_id' => $menuItem->stock_ingredient_id,
                    'modifier_groups' => $menuItem->modifierGroups->map(fn ($g) => [
                        'id' => $g->id,
                        'name' => $g->name,
                        'is_required' => $g->is_required,
                        'max_select' => $g->max_select,
                        'modifiers' => $g->modifiers->map(fn ($m) => [
                            'id' => $m->id,
                            'name' => $m->name,
                            'price_delta' => $m->price_delta,
                        ])->values(),
                    ])->values(),
                    'addons' => $addOns->map(fn ($a) => [
                        'id' => $a->id,
                        'name' => $a->name,
                        'price' => $a->price,
                    ])->values(),
                ],
            ]);
        }

        return response()->json(['found' => false, 'message' => 'No item found for barcode: '.$code], 404);
    }
}
