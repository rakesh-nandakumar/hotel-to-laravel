<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use Database\Seeders\Menu\MenuDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->syncMenuItems(MenuDefinition::tree());
        });
    }

    /**
     * Syncs menu items with definition - creates new, updates existing, removes stale.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function syncMenuItems(array $nodes, ?int $parentId = null): void
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

            $item->fill([
                'icon' => $node['icon'] ?? null,
                'route_name' => $node['route_name'] ?? null,
                'actions' => $node['actions'] ?? [],
                'order' => $order++,
                'is_active' => true,
            ])->save();

            if (! empty($node['children'])) {
                $this->syncMenuItems($node['children'], $item->id);
            }
        }

        // Remove items at this level that are no longer in definition
        MenuItem::where('parent_id', $parentId)
            ->where(function ($query) use ($definedKeys) {
                $query->whereNotIn('module_key', $definedKeys)
                    ->orWhere(function ($q) use ($definedKeys) {
                        $q->whereNull('module_key')
                            ->whereNotIn('name', $definedKeys);
                    });
            })
            ->withTrashed()
            ->forceDelete();
    }
}
