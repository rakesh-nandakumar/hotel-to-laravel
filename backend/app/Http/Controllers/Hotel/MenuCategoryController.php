<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\StoreMenuCategoryRequest;
use App\Http\Requests\Hotel\UpdateMenuCategoryRequest;
use App\Models\Hotel\MenuCategory;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Support\Lookups\LookupType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class MenuCategoryController extends Controller
{
    private const CACHE_KEY = 'pos.menu_categories';
    private const CACHE_TTL = 3600; // 1 hour

    public function index(): JsonResponse
    {
        $categories = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return MenuCategory::query()
                ->withCount('items')
                ->with('kitchenStation')
                ->orderBy('sort_order')
                ->get();
        });

        return response()->json(['menu_categories' => $categories]);
    }

    public function store(StoreMenuCategoryRequest $request): JsonResponse
    {
        $data = $this->resolveKitchenStation($request->validated());
        $category = MenuCategory::create($data);

        AuditLog::record('menu_category.created', $category, ['name' => $category->name]);
        Cache::forget(self::CACHE_KEY);

        return response()->json(['message' => "Category \"{$category->name}\" created.", 'menu_category' => $category->load('kitchenStation')], 201);
    }

    public function update(UpdateMenuCategoryRequest $request, MenuCategory $menuCategory): JsonResponse
    {
        $data = $this->resolveKitchenStation($request->validated());
        $menuCategory->update($data);

        AuditLog::record('menu_category.updated', $menuCategory, ['name' => $menuCategory->name]);
        Cache::forget(self::CACHE_KEY);

        return response()->json(['message' => 'Category updated.', 'menu_category' => $menuCategory->load('kitchenStation')]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveKitchenStation(array $data): array
    {
        if (array_key_exists('kitchen_station', $data)) {
            $data['kitchen_station_id'] = $data['kitchen_station']
                ? Lookup::id(LookupType::KITCHEN_STATION, $data['kitchen_station'])
                : null;
            unset($data['kitchen_station']);
        }

        return $data;
    }

    /** Remove an empty category — must contain no items, active or archived. */
    public function destroy(MenuCategory $menuCategory): JsonResponse
    {
        $itemCount = $menuCategory->items()->count();
        if ($itemCount > 0) {
            throw ValidationException::withMessages([
                'menu_category' => "\"{$menuCategory->name}\" still has {$itemCount} item(s) — move or remove them first.",
            ]);
        }

        $name = $menuCategory->name;
        $menuCategory->delete();

        AuditLog::record('menu_category.deleted', $menuCategory, ['name' => $name]);
        Cache::forget(self::CACHE_KEY);

        return response()->json(['message' => "\"{$name}\" removed."]);
    }
}