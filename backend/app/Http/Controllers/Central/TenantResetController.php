<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Direct tenant reset - clears all operational data for a tenant.
 * This is a simpler alternative to the complex purge system for full resets.
 */
class TenantResetController extends Controller
{
    public function reset(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'current_password:central'],
        ]);

        $tenantId = $tenant->id;

        DB::transaction(function () use ($tenantId) {
            // Disable foreign key checks for bulk deletion
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            // Get available room status ID
            $availableStatusId = DB::table('lookups')
                ->where('type', 'room_status')
                ->where('code', 'available')
                ->value('id');

            // Delete hotel data
            DB::table('reservations')->where('tenant_id', $tenantId)->delete();
            DB::table('reservation_rooms')->where('tenant_id', $tenantId)->delete();
            DB::table('folios')->where('tenant_id', $tenantId)->delete();
            DB::table('folio_lines')->where('tenant_id', $tenantId)->delete();
            DB::table('payments')->where('tenant_id', $tenantId)->delete();
            DB::table('guests')->where('tenant_id', $tenantId)->delete();
            DB::table('group_bookings')->where('tenant_id', $tenantId)->delete();
            DB::table('venue_bookings')->where('tenant_id', $tenantId)->delete();
            DB::table('venue_extra_charges')->where('tenant_id', $tenantId)->delete();
            DB::table('housekeeping_tasks')->where('tenant_id', $tenantId)->delete();
            DB::table('maintenance_issues')->where('tenant_id', $tenantId)->delete();
            DB::table('visitor_logs')->where('tenant_id', $tenantId)->delete();
            DB::table('night_audits')->where('tenant_id', $tenantId)->delete();
            DB::table('notifications')->where('tenant_id', $tenantId)->delete();
            DB::table('loyalty_transactions')->where('tenant_id', $tenantId)->delete();
            DB::table('corporate_accounts')->where('tenant_id', $tenantId)->delete();

            // Delete restaurant/POS data
            DB::table('orders')->where('tenant_id', $tenantId)->delete();
            DB::table('order_items')->where('tenant_id', $tenantId)->delete();
            DB::table('order_item_modifiers')->where('tenant_id', $tenantId)->delete();
            DB::table('dining_areas')->where('tenant_id', $tenantId)->delete();
            DB::table('dining_tables')->where('tenant_id', $tenantId)->delete();
            DB::table('qr_ordering_points')->where('tenant_id', $tenantId)->delete();
            DB::table('pos_menu_categories')->where('tenant_id', $tenantId)->delete();
            DB::table('pos_menu_items')->where('tenant_id', $tenantId)->delete();
            DB::table('menu_item_modifier_groups')->where('tenant_id', $tenantId)->delete();
            DB::table('menu_item_modifiers')->where('tenant_id', $tenantId)->delete();
            DB::table('add_ons')->where('tenant_id', $tenantId)->delete();
            DB::table('add_on_links')->where('tenant_id', $tenantId)->delete();

            // Delete inventory data
            DB::table('ingredient_batches')->where('tenant_id', $tenantId)->delete();
            DB::table('grns')->where('tenant_id', $tenantId)->delete();
            DB::table('grn_lines')->where('tenant_id', $tenantId)->delete();
            DB::table('stock_movements')->where('tenant_id', $tenantId)->delete();
            DB::table('recipe_items')->where('tenant_id', $tenantId)->delete();
            DB::table('ingredients')->where('tenant_id', $tenantId)->delete();

            // Delete till data
            DB::table('till_sessions')->where('tenant_id', $tenantId)->delete();
            DB::table('till_movements')->where('tenant_id', $tenantId)->delete();

            // Reset all rooms to available
            if ($availableStatusId) {
                DB::table('rooms')->where('tenant_id', $tenantId)->update(['room_status_id' => $availableStatusId]);
            }

            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        });

        return response()->json([
            'message' => 'Tenant reset completed successfully. All data has been cleared, rooms set to available, and tills reset.',
        ]);
    }
}
