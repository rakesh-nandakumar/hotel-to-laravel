<?php

namespace App\Services\Tenancy\DataPurge;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;

/**
 * Cached lists that hold tenant rows go stale the moment a purge or restore
 * runs, and would keep offering menu items and categories that no longer exist
 * (an order for one of them is exactly the kind of error a purge must not cause).
 *
 * Mirrors Hotel\MenuItemController::flushMenuItemsCache() and the menu category
 * cache key. Flushing every tenant's copy is harmless — they simply refill.
 */
final class PurgeCaches
{
    public static function flush(): void
    {
        Cache::forget('pos.menu_categories');

        $store = Cache::getStore();

        if ($store instanceof TaggableStore) {
            Cache::tags(['menu_items'])->flush();

            return;
        }

        if ($store instanceof DatabaseStore) {
            $store->getConnection()
                ->table(config('cache.stores.database.table', 'cache'))
                ->where('key', 'like', '%menu_items.index.%')
                ->delete();

            return;
        }

        Cache::flush();
    }
}
