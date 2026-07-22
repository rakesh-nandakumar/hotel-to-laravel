<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use Database\Seeders\Menu\MenuDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MenuSeeder extends Seeder
{
    public function run(?int $tenantId = null): void
    {
        $tenantIds = $tenantId ? [$tenantId] : \App\Models\Tenant::pluck('id')->all();

        DB::transaction(function () use ($tenantIds): void {
            foreach ($tenantIds as $id) {
                MenuItem::withoutGlobalScopes()->where('tenant_id', $id)->forceDelete();
                $this->seedNodes(MenuDefinition::tree(), null, $id);
            }
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function seedNodes(array $nodes, ?int $parentId, int $tenantId): void
    {
        foreach ($nodes as $order => $node) {
            $item = MenuItem::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
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
                $this->seedNodes($node['children'], $item->id, $tenantId);
            }
        }
    }
}
