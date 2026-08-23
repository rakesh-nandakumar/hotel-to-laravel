<?php

namespace App\Http\Controllers\Hotel;

use App\Events\Hotel\RealtimeUpdate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\StoreMenuItemRequest;
use App\Http\Requests\Hotel\ToggleMenuItemSoldOutRequest;
use App\Http\Requests\Hotel\UpdateMenuItemRequest;
use App\Models\Hotel\MenuCategory;
use App\Models\Hotel\MenuItem;
use App\Services\AuditLog;
use App\Services\Hotel\InventoryService;
use App\Support\RealtimeEvent;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\TaggableStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MenuItemController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    /** Full menu for the POS grid — every active staff member can see it. */
    public function full(): JsonResponse
    {
        $categories = MenuCategory::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->with([
                'kitchenStation',
                'items' => fn ($q) => $q->where('active', true)->orderBy('item_no')->orderBy('name')
                    ->with([
                        'modifierGroups' => fn ($g) => $g->orderBy('sort_order')->with(['modifiers' => fn ($m) => $m->where('active', true)->orderBy('sort_order')]),
                        'linkedAddOns' => fn ($a) => $a->active()->orderBy('sort_order'),
                        'categoryAddOns' => fn ($a) => $a->active()->orderBy('sort_order'),
                    ]),
                'products' => fn ($q) => $q->products()->active()->where('stock_qty', '>', 0)->orderBy('name'),
            ])
            ->get();

        // Fold item-scoped + category-scoped add-ons into one `addons` list per item.
        $categories->each(function (MenuCategory $category) {
            $category->items->each(function (MenuItem $item) {
                $item->setAttribute('addons', $item->linkedAddOns->concat($item->categoryAddOns)->unique('id')->values());
                $item->unsetRelation('linkedAddOns')->unsetRelation('categoryAddOns');
            });
        });

        return response()->json(['categories' => $categories]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = MenuItem::query()
            ->with(['category', 'recipe.ingredient', 'modifierGroups' => fn ($g) => $g->orderBy('sort_order')->with(['modifiers' => fn ($m) => $m->orderBy('sort_order')])])
            ->orderBy('item_no')
            ->orderBy('name');

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }
        if ($categoryId = $request->integer('category_id')) {
            $query->where('menu_category_id', $categoryId);
        }
        if ($term = $request->string('q')->toString()) {
            $query->search($term);
        }

        if ($request->has('page')) {
            $cacheKey = 'menu_items.index.'.md5($request->fullUrl());
            $payload = $this->rememberMenuItems($cacheKey, 300, function () use ($query, $request) {
                return [
                    'menu_items' => $query->paginate($request->integer('page_size', 25))->withQueryString(),
                    'stats' => [
                        'on_menu' => MenuItem::query()->where('active', true)->count(),
                        'sold_out' => MenuItem::query()->where('active', true)->where('sold_out', true)->count(),
                        'archived' => MenuItem::query()->where('active', false)->count(),
                    ],
                ];
            });

            return response()->json($payload);
        }

        return response()->json(['menu_items' => $query->get()]);
    }

    public function store(StoreMenuItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        $itemNo = $data['item_no'] ?? ((MenuItem::query()->max('item_no') ?? 0) + 1);

        $item = DB::transaction(function () use ($data, $itemNo) {
            $item = MenuItem::create([
                'name' => $data['name'],
                'menu_category_id' => $data['menu_category_id'],
                'price' => $data['price'],
                'item_no' => $itemNo,
                'description' => $data['description'] ?? '',
                'image' => $data['image'] ?? null,
                'stock_ingredient_id' => $data['stock_ingredient_id'] ?? null,
            ]);

            if (! empty($data['recipe'])) {
                $item->recipe()->createMany($data['recipe']);
            }

            return $item;
        });

        AuditLog::record('menu_item.created', $item, ['item_no' => $itemNo, 'name' => $item->name]);
        $this->flushMenuItemsCache();

        return response()->json([
            'message' => "\"{$item->name}\" created.",
            'menu_item' => $item->load(['category', 'recipe.ingredient']),
        ], 201);
    }

    public function update(UpdateMenuItemRequest $request, MenuItem $menuItem): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($menuItem, $data) {
            if (array_key_exists('recipe', $data)) {
                $menuItem->recipe()->delete();
                $menuItem->recipe()->createMany($data['recipe']);
            }

            $menuItem->update(collect($data)->except('recipe')->all());
        });

        AuditLog::record('menu_item.updated', $menuItem, ['name' => $menuItem->name]);
        $this->flushMenuItemsCache();

        return response()->json([
            'message' => 'Item updated.',
            'menu_item' => $menuItem->fresh()->load(['category', 'recipe.ingredient']),
        ]);
    }

    /**
     * Remove a menu item. Items referenced by past orders should be archived
     * instead (order history must stay intact) — that branch is added by
     * Module 6 once `order_items` exists; until then nothing can reference a
     * menu item, so this always hard-deletes (recipe cascades).
     */
    /**
     * Items that appear in past orders are ARCHIVED (deactivated — order
     * history must stay intact, and `order_items.menu_item_id` is a
     * restrict-on-delete FK); never-ordered items are hard-deleted along
     * with their recipe (cascade).
     */
    public function destroy(MenuItem $menuItem): JsonResponse
    {
        $name = $menuItem->name;
        $pastOrders = $menuItem->orderItems()->count();

        if ($pastOrders > 0) {
            $menuItem->update(['active' => false, 'sold_out' => false]);

            AuditLog::record('menu_item.archived', $menuItem, ['name' => $name, 'past_orders' => $pastOrders]);
            broadcast(new RealtimeUpdate(RealtimeEvent::MENU, ['removed' => [$name]]));
            $this->flushMenuItemsCache();

            return response()->json([
                'archived' => true,
                'message' => "\"{$name}\" appears in {$pastOrders} past order(s) — archived instead of deleted (order history preserved). Restore anytime from the Archived filter.",
            ]);
        }

        $menuItem->delete();

        AuditLog::record('menu_item.deleted', $menuItem, ['name' => $name]);
        broadcast(new RealtimeUpdate(RealtimeEvent::MENU, ['removed' => [$name]]));
        $this->flushMenuItemsCache();

        return response()->json(['archived' => false, 'message' => "\"{$name}\" removed."]);
    }

    /**
     * Sold-out toggle. Re-enabling requires enough raw material for at least
     * one portion — otherwise rejected listing what's missing.
     */
    /** Lightweight menu item search for POS — returns only active, non-sold-out items. */
    public function search(Request $request): JsonResponse
    {
        $q = $request->string('q')->toString();
        $limit = min(50, max(1, $request->integer('limit', 20)));
        $categoryId = $request->integer('category_id');

        $query = MenuItem::query()
            ->with([
                'modifierGroups' => fn ($g) => $g->orderBy('sort_order')->with(['modifiers' => fn ($m) => $m->where('active', true)->orderBy('sort_order')]),
                'linkedAddOns' => fn ($a) => $a->active()->orderBy('sort_order'),
                'categoryAddOns' => fn ($a) => $a->active()->orderBy('sort_order'),
            ])
            ->where('active', true)
            ->where('sold_out', false)
            ->select('id', 'name', 'price', 'item_no', 'description', 'image', 'menu_category_id', 'stock_ingredient_id')
            ->orderBy('item_no')
            ->orderBy('name');

        if ($categoryId) {
            $query->where('menu_category_id', $categoryId);
        }

        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "%{$q}%")
                    ->orWhere('item_no', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        $items = $query->limit($limit)->get()->map(function (MenuItem $item) {
            $addOns = $item->linkedAddOns->concat($item->categoryAddOns)->unique('id')->values();

            return [
                'id' => $item->id,
                'name' => $item->name,
                'price' => $item->price,
                'item_no' => $item->item_no,
                'description' => $item->description,
                'image' => $item->image,
                'menu_category_id' => $item->menu_category_id,
                'stock_ingredient_id' => $item->stock_ingredient_id,
                'modifier_groups' => $item->modifierGroups->map(fn ($g) => [
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
            ];
        });

        return response()->json(['items' => $items]);
    }

    public function toggleSoldOut(ToggleMenuItemSoldOutRequest $request, MenuItem $menuItem): JsonResponse
    {
        $soldOut = $request->boolean('sold_out');

        if (! $soldOut) {
            $check = $this->inventory->canMake($menuItem);
            if (! $check['ok']) {
                throw ValidationException::withMessages([
                    'sold_out' => 'Cannot mark available — insufficient raw materials: '.implode('; ', $check['missing']).'. Restock first.',
                ]);
            }
        }

        $menuItem->update(['sold_out' => $soldOut]);

        AuditLog::record('menu_item.sold_out_toggled', $menuItem, ['sold_out' => $soldOut, 'name' => $menuItem->name]);
        broadcast(new RealtimeUpdate(RealtimeEvent::MENU, [
            'sold_out' => $soldOut ? [$menuItem->name] : [],
            'available' => $soldOut ? [] : [$menuItem->name],
        ]));
        $this->flushMenuItemsCache();

        return response()->json(['message' => 'Item availability updated.', 'menu_item' => $menuItem]);
    }

    /**
     * Tag-aware remember: uses tags when the store supports them, plain remember otherwise.
     */
    private function rememberMenuItems(string $key, int $ttl, \Closure $callback): mixed
    {
        $store = Cache::getStore();

        if ($store instanceof TaggableStore) {
            return Cache::tags(['menu_items'])->remember($key, $ttl, $callback);
        }

        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * Flush menu-items index cache without requiring a taggable store.
     *
     * Database/file/array stores throw BadMethodCallException for tags().
     * We delete the known index keys directly for database, and fall back
     * to a full flush only for non-database stores where targeted deletion
     * is impossible (file hashes, array is request-scoped).
     */
    private function flushMenuItemsCache(): void
    {
        $store = Cache::getStore();

        if ($store instanceof TaggableStore) {
            Cache::tags(['menu_items'])->flush();

            return;
        }

        // Database store: keys are stored in `cache` table with optional prefix.
        // LIKE with % covers both prefixed and non-prefixed keys.
        try {
            DB::table('cache')->where('key', 'like', '%menu_items.index.%')->delete();
        } catch (\Throwable $e) {
            // Table missing (e.g. testing with sqlite :memory: without cache table)
            // or wrong connection — ignore and try generic flush below if needed.
        }

        // For non-database stores (file, array, null) we cannot reliably
        // enumerate hashed file names. Array is request-scoped so flushing
        // is cheap; file flush wipes all but is acceptable since production
        // uses database and tagging would have handled it. Only flush when
        // not using database to avoid wiping unrelated caches (settings, etc.).
        if (! $store instanceof DatabaseStore) {
            try {
                Cache::flush();
            } catch (\Throwable $e) {
            }
        }
    }
}
