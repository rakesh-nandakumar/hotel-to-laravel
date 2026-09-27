<?php

namespace App\Services;

use App\Models\MenuItem;
use Database\Seeders\Menu\MenuDefinition;
use Illuminate\Support\Facades\DB;

class MenuSync
{
    /**
     * Syncs menu items with definition - creates new, updates existing, removes stale.
     * Returns statistics about the sync operation.
     *
     * @return array{created: int, updated: int, removed: int}
     */
    public function sync(): array
    {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'removed' => 0,
        ];

        DB::transaction(function () use (&$stats): void {
            $this->syncMenuItems(MenuDefinition::tree(), null, $stats);
        });

        return $stats;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array{created: int, updated: int, removed: int}  $stats
     */
    private function syncMenuItems(array $nodes, ?int $parentId, array &$stats): void
    {
        $definedKeys = [];
        $order = 0;

        foreach ($nodes as $node) {
            $moduleKey = $node['module_key'] ?? null;
            $key = $moduleKey ?: $node['name'];

            $definedKeys[] = $key;

            $item = MenuItem::firstOrNew([
                'parent_id' => $parentId,
                'module_key' => $moduleKey,
                'name' => $node['name'],
            ]);

            $wasRecentlyCreated = ! $item->exists;
            $item->fill([
                'icon' => $node['icon'] ?? null,
                'route_name' => $node['route_name'] ?? null,
                'actions' => $node['actions'] ?? [],
                'order' => $order++,
                'is_active' => true,
            ])->save();

            if ($wasRecentlyCreated) {
                $stats['created']++;
            } else {
                $stats['updated']++;
            }

            if (! empty($node['children'])) {
                $this->syncMenuItems($node['children'], $item->id, $stats);
            }
        }

        // Remove items at this level that are no longer in definition
        $removedCount = MenuItem::where('parent_id', $parentId)
            ->where(function ($query) use ($definedKeys) {
                $query->whereNotIn('module_key', $definedKeys)
                    ->orWhere(function ($q) use ($definedKeys) {
                        $q->whereNull('module_key')
                            ->whereNotIn('name', $definedKeys);
                    });
            })
            ->withTrashed()
            ->forceDelete();

        $stats['removed'] += $removedCount;
    }
}
