<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\StoreAddOnRequest;
use App\Http\Requests\Hotel\UpdateAddOnRequest;
use App\Models\Hotel\AddOn;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddOnController extends Controller
{
    private const WITH_LINKS = [
        'links.menuItem:id,name',
        'links.menuCategory:id,name',
        'stockIngredient:id,name,unit',
    ];

    public function index(Request $request): JsonResponse
    {
        $query = AddOn::query()->with(self::WITH_LINKS)->orderBy('sort_order')->orderBy('name');

        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }

        return response()->json(['add_ons' => $query->get()]);
    }

    public function store(StoreAddOnRequest $request): JsonResponse
    {
        $data = $request->validated();

        $addOn = DB::transaction(function () use ($data) {
            $addOn = AddOn::create([
                'name' => $data['name'],
                'price' => $data['price'],
                'active' => $data['active'] ?? true,
                'stock_ingredient_id' => $data['stock_ingredient_id'] ?? null,
            ]);

            $this->syncLinks($addOn, $data);

            return $addOn;
        });

        AuditLog::record('add_on.created', $addOn, ['name' => $addOn->name, 'price' => $addOn->price]);

        return response()->json([
            'message' => "\"{$addOn->name}\" created.",
            'add_on' => $addOn->load(self::WITH_LINKS),
        ], 201);
    }

    public function update(UpdateAddOnRequest $request, AddOn $addOn): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($addOn, $data) {
            $addOn->update(collect($data)->except(['menu_item_ids', 'menu_category_ids'])->all());
            $this->syncLinks($addOn, $data);
        });

        AuditLog::record('add_on.updated', $addOn, ['name' => $addOn->name]);

        return response()->json([
            'message' => 'Add-on updated.',
            'add_on' => $addOn->fresh()->load(self::WITH_LINKS),
        ]);
    }

    public function destroy(AddOn $addOn): JsonResponse
    {
        $name = $addOn->name;

        if ($addOn->orderItems()->count() > 0) {
            $addOn->update(['active' => false]);

            AuditLog::record('add_on.archived', $addOn, ['name' => $name, 'past_orders' => $addOn->orderItems()->count()]);

            return response()->json([
                'archived' => true,
                'message' => "\"{$name}\" appears in past order(s) — deactivated instead of deleted (order history preserved).",
            ]);
        }

        $addOn->delete();

        AuditLog::record('add_on.deleted', $addOn, ['name' => $name]);

        return response()->json(['archived' => false, 'message' => "\"{$name}\" removed."]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncLinks(AddOn $addOn, array $data): void
    {
        if (! array_key_exists('menu_item_ids', $data) && ! array_key_exists('menu_category_ids', $data)) {
            return;
        }

        $addOn->links()->delete();

        $menuItemIds = array_unique($data['menu_item_ids'] ?? []);
        $menuCategoryIds = array_unique($data['menu_category_ids'] ?? []);

        foreach ($menuItemIds as $menuItemId) {
            $addOn->links()->create(['menu_item_id' => $menuItemId]);
        }
        foreach ($menuCategoryIds as $menuCategoryId) {
            $addOn->links()->create(['menu_category_id' => $menuCategoryId]);
        }
    }
}
