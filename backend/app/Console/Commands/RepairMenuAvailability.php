<?php

namespace App\Console\Commands;

use App\Models\Hotel\MenuItem;
use App\Services\Hotel\InventoryService;
use Illuminate\Console\Command;

class RepairMenuAvailability extends Command
{
    protected $signature = 'hotel:repair-menu-availability';

    protected $description = 'Clear stale SOLD OUT flags from menu items that currently have usable inventory';

    public function handle(InventoryService $inventory): int
    {
        $items = MenuItem::query()
            ->where('active', true)
            ->where('sold_out', true)
            ->with([
                'recipe.ingredient',
                'stockIngredient',
            ])
            ->get();

        $cleared = 0;

        foreach ($items as $item) {
            $availability = $inventory->canMake($item);

            if ($availability['ok']) {
                $item->update([
                    'sold_out' => false,
                ]);

                $this->info(
                    "Cleared stale SOLD OUT: {$item->name}"
                );

                $cleared++;
            }
        }

        $this->newLine();

        $this->info(
            "Availability repair complete. {$cleared} item(s) repaired."
        );

        return self::SUCCESS;
    }
}