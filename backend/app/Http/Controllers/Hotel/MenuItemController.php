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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MenuItemController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    /** Full menu for the POS grid — every active staff member can see it. */
    public function full(): JsonResponse
    {
        return response()->json([
            'categories' => MenuCategory::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->with(['items' => fn ($q) => $q->where('active', true)->orderBy('item_no')->orderBy('name')])
                ->get(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = MenuItem::query()
            ->with(['category', 'recipe.ingredient'])
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
            return response()->json([
                'menu_items' => $query->paginate($request->integer('page_size', 25))->withQueryString(),
                'stats' => [
                    'on_menu' => MenuItem::query()->where('active', true)->count(),
                    'sold_out' => MenuItem::query()->where('active', true)->where('sold_out', true)->count(),
                    'archived' => MenuItem::query()->where('active', false)->count(),
                ],
            ]);
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
            ]);

            if (! empty($data['recipe'])) {
                $item->recipe()->createMany($data['recipe']);
            }

            return $item;
        });

        AuditLog::record('menu_item.created', $item, ['item_no' => $itemNo, 'name' => $item->name]);

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

            return response()->json([
                'archived' => true,
                'message' => "\"{$name}\" appears in {$pastOrders} past order(s) — archived instead of deleted (order history preserved). Restore anytime from the Archived filter.",
            ]);
        }

        $menuItem->delete();

        AuditLog::record('menu_item.deleted', $menuItem, ['name' => $name]);
        broadcast(new RealtimeUpdate(RealtimeEvent::MENU, ['removed' => [$name]]));

        return response()->json(['archived' => false, 'message' => "\"{$name}\" removed."]);
    }

    /**
     * Sold-out toggle. Re-enabling requires enough raw material for at least
     * one portion — otherwise rejected listing what's missing.
     */
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

        return response()->json(['message' => 'Item availability updated.', 'menu_item' => $menuItem]);
    }
}
