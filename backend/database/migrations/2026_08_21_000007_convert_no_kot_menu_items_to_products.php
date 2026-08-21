<?php

use App\Support\Lookups\InventoryKind;
use App\Support\Lookups\LookupType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retires the `send_to_kot` toggle on `pos_menu_items` and `add_ons` — KOT
     * routing is now derived from line kind (menu item/add-on = kitchen,
     * product = direct-fulfil), not a per-row flag. Every menu item currently
     * marked `send_to_kot = false` is converted to a Product first: its
     * `stock_ingredient_id` ingredient (if any) is promoted, otherwise a new
     * product ingredient is created; the menu item itself is archived
     * (`active = false`), never deleted, so order history and its
     * `order_items.menu_item_id` / `send_to_kot` snapshot stay intact.
     */
    public function up(): void
    {
        $productKindId = DB::table('lookups')
            ->where('type', LookupType::INVENTORY_KIND)->where('code', InventoryKind::PRODUCT)->value('id');

        $items = DB::table('pos_menu_items')->where('send_to_kot', false)->get();

        foreach ($items as $item) {
            if ($item->stock_ingredient_id) {
                DB::table('ingredients')->where('id', $item->stock_ingredient_id)->update([
                    'inventory_kind_id' => $productKindId,
                    'selling_price' => $item->price,
                    'menu_category_id' => $item->menu_category_id,
                    'image' => $item->image,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('ingredients')->insert([
                    'tenant_id' => $item->tenant_id,
                    'name' => $item->name,
                    'unit' => 'pcs',
                    'stock_qty' => 0,
                    'low_stock_threshold' => 0,
                    'inventory_kind_id' => $productKindId,
                    'selling_price' => $item->price,
                    'menu_category_id' => $item->menu_category_id,
                    'image' => $item->image,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('pos_menu_items')->where('id', $item->id)->update(['active' => false]);
        }

        Schema::table('pos_menu_items', function (Blueprint $table) {
            $table->dropColumn('send_to_kot');
        });
        Schema::table('add_ons', function (Blueprint $table) {
            $table->dropColumn('send_to_kot');
        });
    }

    public function down(): void
    {
        Schema::table('pos_menu_items', function (Blueprint $table) {
            $table->boolean('send_to_kot')->default(true)->after('sold_out');
        });
        Schema::table('add_ons', function (Blueprint $table) {
            $table->boolean('send_to_kot')->default(true);
        });

        // The item→product conversion itself is not reversed — which product
        // rows were auto-created vs. pre-existing can't be reliably
        // distinguished after the fact.
    }
};
