<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\Tenant;
use App\Support\ModuleCatalog;

/**
 * Builds the role-permission matrix shown by the SPA's PermissionMatrix
 * component — used both by the tenant's own Roles editor and by master
 * control's per-tenant Roles tab (see UserManagement\RoleController and
 * Central\TenantRoleController).
 *
 * Sections come from the seeded menu tree (Database\Seeders\Menu\MenuDefinition).
 * Each section is tagged with the licensable module group it belongs to (see
 * App\Support\ModuleCatalog), so the UI can grant/revoke a whole group
 * ("Hotel Operations", "Restaurant / POS", ...) at once instead of
 * permission-by-permission.
 *
 * When a Tenant is given, modules are filtered by THAT tenant's licences
 * (a platform operator editing roles must not be able to grant what the
 * tenant isn't licensed for — licensing outranks permissions). When null,
 * the current request's own tenant context decides, as before.
 */
class PermissionMatrixBuilder
{
    /**
     * @return array{
     *   matrix: array<int, array{section: string, group: string, modules: array<int, array{key: string, label: string, actions: array<int, string>}>}>,
     *   groups: array<int, array{key: string, label: string}>,
     *   allActions: array<int, string>,
     * }
     */
    public function for(?Tenant $tenant = null): array
    {
        $sections = MenuItem::with('children')
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('order')
            ->get();

        $matrix = [];

        foreach ($sections as $section) {
            $modules = $section->isGroup()
                ? $section->children
                : collect([$section]);

            $enabled = $modules
                ->filter(fn (MenuItem $m) => $m->module_key !== null && $this->isModuleEnabled($m->module_key, $tenant))
                ->map(fn (MenuItem $m) => [
                    'key' => $m->module_key,
                    'label' => $m->name,
                    'actions' => $m->actions ?? [],
                ])
                ->values()
                ->all();

            if ($enabled === []) {
                continue;
            }

            $matrix[] = [
                'section' => $section->name,
                'group' => $this->groupKeyFor($enabled[0]['key']),
                'modules' => $enabled,
            ];
        }

        $groups = collect(ModuleCatalog::definitions())
            ->map(fn (array $definition, string $key) => ['key' => $key, 'label' => $definition['name']])
            ->values()
            ->push(['key' => 'core', 'label' => 'Core (always on)'])
            ->all();

        $allActions = collect($matrix)
            ->flatMap(fn (array $section) => collect($section['modules'])->flatMap(fn (array $module) => $module['actions']))
            ->unique()
            ->values()
            ->all();

        return [
            'matrix' => $matrix,
            'groups' => $groups,
            'allActions' => $allActions,
        ];
    }

    private function isModuleEnabled(string $moduleKey, ?Tenant $tenant): bool
    {
        if ($tenant === null) {
            return TenantModules::isEnabled($moduleKey);
        }

        $catalogKey = ModuleCatalog::catalogKeyFor($moduleKey);
        if ($catalogKey === null) {
            return true; // core — always on
        }

        return TenantModules::enabledKeysFor($tenant->id)->contains($catalogKey);
    }

    private function groupKeyFor(string $moduleKey): string
    {
        return ModuleCatalog::catalogKeyFor($moduleKey) ?? 'core';
    }
}
