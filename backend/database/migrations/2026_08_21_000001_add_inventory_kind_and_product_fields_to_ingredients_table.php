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
     * `ingredients` now classifies every row as an `ingredient` (raw material,
     * consumed via a recipe) or a `product` (directly sellable, no recipe) —
     * see App\Support\Lookups\InventoryKind. `selling_price`, `menu_category_id`
     * and `image` are product-only fields. Every existing row backfills to
     * `ingredient`; converted products are populated by the migration that
     * retires `send_to_kot`.
     */
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->foreignId('inventory_kind_id')->nullable()->after('tenant_id')
                ->constrained('lookups')->nullOnDelete();
            $table->unsignedInteger('selling_price')->nullable()->after('unit_cost');
            $table->foreignId('menu_category_id')->nullable()->after('selling_price')
                ->constrained('pos_menu_categories')->nullOnDelete();
            $table->longText('image')->nullable();
            $table->boolean('active')->default(true);
        });

        $now = now();
        DB::table('lookups')->insertOrIgnore([
            ['type' => LookupType::INVENTORY_KIND, 'code' => InventoryKind::INGREDIENT, 'name' => 'Ingredient', 'color' => 'orange', 'sort_order' => 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['type' => LookupType::INVENTORY_KIND, 'code' => InventoryKind::PRODUCT, 'name' => 'Product', 'color' => 'blue', 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $ingredientKindId = DB::table('lookups')
            ->where('type', LookupType::INVENTORY_KIND)->where('code', InventoryKind::INGREDIENT)->value('id');

        DB::table('ingredients')->whereNull('inventory_kind_id')->update(['inventory_kind_id' => $ingredientKindId]);
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn(['image', 'active']);
            $table->dropConstrainedForeignId('menu_category_id');
            $table->dropColumn('selling_price');
            $table->dropConstrainedForeignId('inventory_kind_id');
        });
    }
};
