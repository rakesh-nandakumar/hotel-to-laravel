<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

class MenuRenderer
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forUser(User $user): array
    {
        $tree = MenuItem::with('children.children.children')
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('order')
            ->get();

        return self::filterTree($tree, $user)->values()->all();
    }

    /**
     * @param  Collection<int, MenuItem>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private static function filterTree(Collection $items, User $user): Collection
    {
        return $items
            ->filter(fn (MenuItem $item): bool => $item->is_active)
            ->map(function (MenuItem $item) use ($user): ?array {
                $filteredChildren = self::filterTree($item->children, $user);

                $isLeafVisible = $item->route_name !== null
                    && ($item->module_key === null
                        || $user->hasPermissionTo("{$item->module_key}.access"));

                $isGroupVisible = $item->route_name === null
                    && $filteredChildren->isNotEmpty();

                if (! $isLeafVisible && ! $isGroupVisible) {
                    return null;
                }

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'icon' => $item->icon,
                    'href' => $item->route_name && Route::has($item->route_name)
                        ? (static function () use ($item): ?string {
                            try {
                                return route($item->route_name);
                            } catch (\Illuminate\Routing\Exceptions\UrlGenerationException) {
                                return null;
                            }
                        })()
                        : null,
                    'route' => $item->route_name,
                    'children' => $filteredChildren->values()->all(),
                ];
            })
            ->filter()
            ->values();
    }
}
