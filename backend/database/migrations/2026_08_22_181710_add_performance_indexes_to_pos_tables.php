<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ingredients (products) - for search and filtering
        Schema::table('ingredients', function (Blueprint $table) {
            $table->index(['tenant_id', 'menu_category_id', 'active', 'stock_qty'], 'ing_cat_stock_idx');
            $table->index(['tenant_id', 'name'], 'ing_name_search_idx');
        });

        // Ingredient batches - for expiry and FIFO queries
        Schema::table('ingredient_batches', function (Blueprint $table) {
            $table->index(['ingredient_id', 'qty', 'expiry_date'], 'batch_fifo_idx');
            $table->index(['expiry_date', 'qty'], 'batch_expiry_idx');
        });

        // Orders - for POS active/today queries
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['tenant_id', 'order_status_id', 'created_at'], 'order_status_created_idx');
            $table->index(['tenant_id', 'order_type_id', 'order_status_id', 'created_at'], 'order_type_status_created_idx');
            $table->index(['tenant_id', 'kot_status_id', 'created_at'], 'order_kot_created_idx');
            $table->index(['tenant_id', 'dining_table_id', 'order_status_id'], 'order_table_status_idx');
            $table->index(['tenant_id', 'room_id', 'order_status_id'], 'order_room_status_idx');
            $table->index(['tenant_id', 'delivery_status_id', 'order_status_id'], 'order_delivery_status_idx');
            $table->index('client_key', 'order_client_key_idx');
        });

        // Order items - for order detail and void operations
        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['order_id', 'voided'], 'order_item_voided_idx');
            $table->index(['menu_item_id', 'voided'], 'order_item_menu_voided_idx');
            $table->index(['product_id', 'voided'], 'order_item_product_voided_idx');
            $table->index(['add_on_id', 'voided'], 'order_item_addon_voided_idx');
            $table->index(['order_id', 'send_to_kot', 'voided'], 'order_item_kot_voided_idx');
        });

        // Payments - for settlement and refund queries
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['order_id', 'payment_kind_id'], 'payment_order_kind_idx');
            $table->index(['order_id', 'idempotency_key'], 'payment_order_idem_idx');
        });

        // Menu items - for POS search and category filtering (tenant_id may not be available in all envs)
        Schema::table('pos_menu_items', function (Blueprint $table) {
            $table->index(['menu_category_id', 'active', 'sold_out'], 'menu_cat_active_sold_idx');
            $table->index(['active', 'sold_out', 'item_no'], 'menu_active_sold_no_idx');
            $table->index(['name'], 'menu_name_search_idx');
            $table->index('item_no', 'menu_item_no_idx');
        });

        // Add-ons - for POS search
        Schema::table('add_ons', function (Blueprint $table) {
            $table->index(['tenant_id', 'active'], 'addon_active_idx');
            $table->index(['tenant_id', 'name'], 'addon_name_search_idx');
        });

        // Guests - for customer lookup
        Schema::table('guests', function (Blueprint $table) {
            $table->index(['tenant_id', 'name'], 'guest_name_search_idx');
            $table->index(['tenant_id', 'phone'], 'guest_phone_search_idx');
            $table->index(['tenant_id', 'email'], 'guest_email_search_idx');
        });

        // Stock movements - for audit and reporting
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['ingredient_id', 'created_at'], 'stock_mov_ing_created_idx');
            $table->index(['tenant_id', 'movement_type_id', 'created_at'], 'stock_mov_type_created_idx');
            $table->index(['reference_type', 'reference_id'], 'stock_mov_ref_idx');
        });

        // Dining tables - for floor plan
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->index(['tenant_id', 'dining_area_id', 'table_status_id'], 'table_area_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropIndex('ing_cat_stock_idx');
            $table->dropIndex('ing_name_search_idx');
        });

        Schema::table('ingredient_batches', function (Blueprint $table) {
            $table->dropIndex('batch_fifo_idx');
            $table->dropIndex('batch_expiry_idx');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('order_status_created_idx');
            $table->dropIndex('order_type_status_created_idx');
            $table->dropIndex('order_kot_created_idx');
            $table->dropIndex('order_table_status_idx');
            $table->dropIndex('order_room_status_idx');
            $table->dropIndex('order_delivery_status_idx');
            $table->dropIndex('order_client_key_idx');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('order_item_voided_idx');
            $table->dropIndex('order_item_menu_voided_idx');
            $table->dropIndex('order_item_product_voided_idx');
            $table->dropIndex('order_item_addon_voided_idx');
            $table->dropIndex('order_item_kot_voided_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payment_order_kind_idx');
            $table->dropIndex('payment_order_idem_idx');
        });

        Schema::table('pos_menu_items', function (Blueprint $table) {
            $table->dropIndex('menu_cat_active_sold_idx');
            $table->dropIndex('menu_active_sold_no_idx');
            $table->dropIndex('menu_name_search_idx');
            $table->dropIndex('menu_item_no_idx');
        });

        Schema::table('add_ons', function (Blueprint $table) {
            $table->dropIndex('addon_active_idx');
            $table->dropIndex('addon_name_search_idx');
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->dropIndex('guest_name_search_idx');
            $table->dropIndex('guest_phone_search_idx');
            $table->dropIndex('guest_email_search_idx');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_mov_ing_created_idx');
            $table->dropIndex('stock_mov_type_created_idx');
            $table->dropIndex('stock_mov_ref_idx');
        });

        Schema::table('dining_tables', function (Blueprint $table) {
            $table->dropIndex('table_area_status_idx');
        });
    }
};
