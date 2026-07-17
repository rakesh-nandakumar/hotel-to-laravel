<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\StoreMenuCategoryRequest;
use App\Http\Requests\Hotel\UpdateMenuCategoryRequest;
use App\Models\Hotel\MenuCategory;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class MenuCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'menu_categories' => MenuCategory::query()->withCount('items')->orderBy('sort_order')->get(),
        ]);
    }

    public function store(StoreMenuCategoryRequest $request): JsonResponse
    {
        $category = MenuCategory::create($request->validated());

        AuditLog::record('menu_category.created', $category, ['name' => $category->name]);

        return response()->json(['message' => "Category \"{$category->name}\" created.", 'menu_category' => $category], 201);
    }

    public function update(UpdateMenuCategoryRequest $request, MenuCategory $menuCategory): JsonResponse
    {
        $menuCategory->update($request->validated());

        AuditLog::record('menu_category.updated', $menuCategory, ['name' => $menuCategory->name]);

        return response()->json(['message' => 'Category updated.', 'menu_category' => $menuCategory]);
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

        return response()->json(['message' => "\"{$name}\" removed."]);
    }
}
