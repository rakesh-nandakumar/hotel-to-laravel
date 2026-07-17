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
            MenuItem::withTrashed()->forceDelete();

            $this->seedNodes(MenuDefinition::tree(), parentId: null);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function seedNodes(array $nodes, ?int $parentId): void
    {
        foreach ($nodes as $order => $node) {
            $item = MenuItem::create([
                'parent_id' => $parentId,
                'name' => $node['name'],
                'icon' => $node['icon'] ?? null,
                'route_name' => $node['route_name'] ?? null,
                'module_key' => $node['module_key'] ?? null,
                'actions' => $node['actions'] ?? [],
                'order' => $order,
                'is_active' => true,
            ]);

            if (! empty($node['children'])) {
                $this->seedNodes($node['children'], $item->id);
            }
        }
    }
}
