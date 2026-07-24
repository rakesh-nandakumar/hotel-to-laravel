<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\StoreMenuItemModifierGroupRequest;
use App\Http\Requests\Hotel\StoreMenuItemModifierRequest;
use App\Http\Requests\Hotel\UpdateMenuItemModifierGroupRequest;
use App\Http\Requests\Hotel\UpdateMenuItemModifierRequest;
use App\Models\Hotel\MenuItem;
use App\Models\Hotel\MenuItemModifier;
use App\Models\Hotel\MenuItemModifierGroup;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Modifier groups/options (Size, Spice level, Extras…) nested under a menu
 * item — mirrors RoomTypeController's nested seasonal-rate actions rather
 * than getting its own top-level module_key; gated by hotel_menu_items.edit.
 */
class MenuItemModifierController extends Controller
{
    public function storeGroup(StoreMenuItemModifierGroupRequest $request, MenuItem $menuItem): JsonResponse
    {
        $group = $menuItem->modifierGroups()->create($request->validated());

        AuditLog::record('menu_item.modifier_group_added', $menuItem, ['group' => $group->name]);

        return response()->json(['message' => 'Modifier group added.', 'modifier_group' => $group], 201);
    }

    public function updateGroup(UpdateMenuItemModifierGroupRequest $request, MenuItemModifierGroup $modifierGroup): JsonResponse
    {
        $modifierGroup->update($request->validated());

        AuditLog::record('menu_item.modifier_group_updated', $modifierGroup, ['group' => $modifierGroup->name]);

        return response()->json(['message' => 'Modifier group updated.', 'modifier_group' => $modifierGroup]);
    }

    public function destroyGroup(Request $request, MenuItemModifierGroup $modifierGroup): JsonResponse
    {
        if (! $request->user()?->hasPermissionTo('hotel_menu_items.edit')) {
            abort(403);
        }

        $name = $modifierGroup->name;
        $modifierGroup->delete();

        AuditLog::record('menu_item.modifier_group_removed', null, ['group' => $name]);

        return response()->json(['message' => "\"{$name}\" removed."]);
    }

    public function storeModifier(StoreMenuItemModifierRequest $request, MenuItemModifierGroup $modifierGroup): JsonResponse
    {
        $modifier = $modifierGroup->modifiers()->create($request->validated());

        AuditLog::record('menu_item.modifier_added', $modifierGroup, ['modifier' => $modifier->name]);

        return response()->json(['message' => 'Modifier added.', 'modifier' => $modifier], 201);
    }

    public function updateModifier(UpdateMenuItemModifierRequest $request, MenuItemModifier $modifier): JsonResponse
    {
        $modifier->update($request->validated());

        AuditLog::record('menu_item.modifier_updated', $modifier, ['modifier' => $modifier->name]);

        return response()->json(['message' => 'Modifier updated.', 'modifier' => $modifier]);
    }

    public function destroyModifier(Request $request, MenuItemModifier $modifier): JsonResponse
    {
        if (! $request->user()?->hasPermissionTo('hotel_menu_items.edit')) {
            abort(403);
        }

        $name = $modifier->name;
        $modifier->delete();

        AuditLog::record('menu_item.modifier_removed', null, ['modifier' => $name]);

        return response()->json(['message' => "\"{$name}\" removed."]);
    }
}
