<?php

use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\DeviceTokenController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\OtpChallengeController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\PinLoginController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Hotel\AttendanceController;
use App\Http\Controllers\Hotel\CorporateAccountController;
use App\Http\Controllers\Hotel\FolioController;
use App\Http\Controllers\Hotel\GuestController;
use App\Http\Controllers\Hotel\HousekeepingTaskController;
use App\Http\Controllers\Hotel\IngredientController;
use App\Http\Controllers\Hotel\LaundryController;
use App\Http\Controllers\Hotel\MaintenanceIssueController;
use App\Http\Controllers\Hotel\MenuCategoryController;
use App\Http\Controllers\Hotel\MenuItemController;
use App\Http\Controllers\Hotel\NotificationController;
use App\Http\Controllers\Hotel\OrderController;
use App\Http\Controllers\Hotel\PackageController;
use App\Http\Controllers\Hotel\PayrollController;
use App\Http\Controllers\Hotel\PublicController;
use App\Http\Controllers\Hotel\ReportController;
use App\Http\Controllers\Hotel\ReservationController;
use App\Http\Controllers\Hotel\RoomController;
use App\Http\Controllers\Hotel\RoomTypeController;
use App\Http\Controllers\Hotel\SettingController;
use App\Http\Controllers\Hotel\ShiftController;
use App\Http\Controllers\Hotel\StaffController;
use App\Http\Controllers\Hotel\VenueBookingController;
use App\Http\Controllers\Hotel\VenueController;
use App\Http\Controllers\Hotel\VisitorLogController;
use App\Http\Controllers\Profile\TwoFactorController;
use App\Http\Controllers\Settings\BrowserSessionsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\UserManagement\RoleController;
use App\Http\Controllers\UserManagement\UserManagementUserController;
use Illuminate\Support\Facades\Route;

// ── Guest auth ──────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('otp-challenge', [OtpChallengeController::class, 'create'])->name('otp.login');
    Route::post('otp-challenge', [OtpChallengeController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('otp.login.store');
    Route::post('otp-challenge/resend', [OtpChallengeController::class, 'resend'])
        ->middleware('throttle:3,1')
        ->name('otp.login.resend');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('reset-password', [NewPasswordController::class, 'create'])
        ->middleware('signed')
        ->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

// PIN quick-unlock — deliberately outside the `guest` group (a terminal may
// already hold a session for a different staff member switching shifts) and
// outside `auth` (that's the whole point: no prior session is required, only
// a device token from an earlier full login on this device).
Route::post('pin-login', [PinLoginController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('pin-login');

// ── Public (unauthenticated, hotel guest-facing) ─────────────────────────────
Route::prefix('public')->name('public.')->group(function () {
    Route::get('branding', [PublicController::class, 'branding'])->name('branding');
    Route::post('pre-checkin', [PublicController::class, 'preCheckIn'])->name('pre-checkin');
    Route::get('venues', [PublicController::class, 'venues'])->name('venues');
    Route::post('venue-inquiry', [PublicController::class, 'venueInquiry'])->name('venue-inquiry');
});

// ── Authenticated ────────────────────────────────────────────────────────────
Route::middleware(['auth', 'check_active'])->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('me', [MeController::class, 'show'])->name('me');
    Route::post('device-token', [DeviceTokenController::class, 'store'])->name('device-token.store');

    Route::get('email/verify', EmailVerificationPromptController::class)->name('verification.notice');
    Route::get('email/verify/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.confirm');

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get('dashboard', [DashboardController::class, 'index'])
        ->middleware('can_do:dashboard.access')
        ->name('dashboard');


    // ── Settings / Profile ─────────────────────────────────────────────────────
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::delete('settings/browser-sessions', [BrowserSessionsController::class, 'destroy'])->name('browser-sessions.destroy');
    Route::delete('settings/browser-sessions/{session}', [BrowserSessionsController::class, 'destroySingle'])->name('browser-sessions.destroy-single');

    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/two-factor', [TwoFactorController::class, 'setup'])->name('profile.two-factor.setup');
    Route::post('settings/two-factor/email', [TwoFactorController::class, 'enableEmail'])->name('profile.two-factor.email.enable');
    Route::delete('settings/two-factor/email', [TwoFactorController::class, 'disableEmail'])->name('profile.two-factor.email.disable');
    Route::post('settings/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
        ->middleware('throttle:6,1')
        ->name('profile.two-factor.recovery-codes');

    // ── User Management ───────────────────────────────────────────────────────
    Route::prefix('user-management')->name('user-management.')->group(function () {
        Route::post('users/bulk', [UserManagementUserController::class, 'bulkAction'])
            ->middleware('can_do:user_management_users.bulk_delete')
            ->name('users.bulk');

        Route::post('users/{user}/suspend', [UserManagementUserController::class, 'suspend'])
            ->middleware('can_do:user_management_users.edit')
            ->name('users.suspend');
        Route::post('users/{user}/reactivate', [UserManagementUserController::class, 'reactivate'])
            ->middleware('can_do:user_management_users.edit')
            ->name('users.reactivate');
        Route::post('users/{user}/deactivate', [UserManagementUserController::class, 'deactivate'])
            ->middleware('can_do:user_management_users.edit')
            ->name('users.deactivate');
        Route::post('users/{user}/unlock', [UserManagementUserController::class, 'unlock'])
            ->middleware('can_do:user_management_users.unlock')
            ->name('users.unlock');
        Route::post('users/{user}/reset-password', [UserManagementUserController::class, 'resetPassword'])
            ->middleware('can_do:user_management_users.reset_password')
            ->name('users.reset-password');

        Route::get('users', [UserManagementUserController::class, 'index'])
            ->middleware('can_do:user_management_users.access')
            ->name('users.index');
        Route::get('users/create', [UserManagementUserController::class, 'create'])
            ->middleware('can_do:user_management_users.create')
            ->name('users.create');
        Route::post('users', [UserManagementUserController::class, 'store'])
            ->middleware('can_do:user_management_users.create')
            ->name('users.store');
        Route::get('users/{user}', [UserManagementUserController::class, 'show'])
            ->middleware('can_do:user_management_users.view')
            ->name('users.show');
        Route::get('users/{user}/edit', [UserManagementUserController::class, 'edit'])
            ->middleware('can_do:user_management_users.edit')
            ->name('users.edit');
        Route::put('users/{user}', [UserManagementUserController::class, 'update'])
            ->middleware('can_do:user_management_users.edit')
            ->name('users.update');
        Route::delete('users/{user}', [UserManagementUserController::class, 'destroy'])
            ->middleware('can_do:user_management_users.delete')
            ->name('users.destroy');

        Route::post('roles/{role}/duplicate', [RoleController::class, 'duplicate'])
            ->middleware('can_do:user_management_roles.duplicate')
            ->name('roles.duplicate');
        Route::post('roles/{role}/toggle-active', [RoleController::class, 'toggleActive'])
            ->middleware('can_do:user_management_roles.toggle_active')
            ->name('roles.toggle-active');

        Route::get('roles', [RoleController::class, 'index'])
            ->middleware('can_do:user_management_roles.access')
            ->name('roles.index');
        Route::get('roles/create', [RoleController::class, 'create'])
            ->middleware('can_do:user_management_roles.create')
            ->name('roles.create');
        Route::post('roles', [RoleController::class, 'store'])
            ->middleware('can_do:user_management_roles.create')
            ->name('roles.store');
        Route::get('roles/{role}', [RoleController::class, 'show'])
            ->middleware('can_do:user_management_roles.view')
            ->name('roles.show');
        Route::get('roles/{role}/edit', [RoleController::class, 'edit'])
            ->middleware('can_do:user_management_roles.edit')
            ->name('roles.edit');
        Route::put('roles/{role}', [RoleController::class, 'update'])
            ->middleware('can_do:user_management_roles.edit')
            ->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])
            ->middleware('can_do:user_management_roles.delete')
            ->name('roles.destroy');
    });

    // ── Audit Logs ────────────────────────────────────────────────────────────
    Route::get('audit-logs', [AuditLogController::class, 'index'])
        ->middleware('can_do:audit_logs.access')
        ->name('audit-logs.index');
    Route::get('audit-logs/export', [AuditLogController::class, 'export'])
        ->middleware('can_do:audit_logs.export')
        ->name('audit-logs.export');
    Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])
        ->middleware('can_do:audit_logs.view')
        ->name('audit-logs.show');

    // ── Rooms ─────────────────────────────────────────────────────────────────
    Route::prefix('rooms')->name('hotel.')->group(function () {
        Route::get('types', [RoomTypeController::class, 'index'])
            ->middleware('can_do:hotel_room_types.access')
            ->name('room-types.index');
        Route::post('types', [RoomTypeController::class, 'store'])
            ->middleware('can_do:hotel_room_types.create')
            ->name('room-types.store');
        Route::put('types/{roomType}', [RoomTypeController::class, 'update'])
            ->middleware('can_do:hotel_room_types.edit')
            ->name('room-types.update');
        Route::post('types/{roomType}/seasonal', [RoomTypeController::class, 'storeSeasonalRate'])
            ->middleware('can_do:hotel_room_types.edit')
            ->name('room-types.seasonal.store');
        Route::delete('seasonal/{seasonalRate}', [RoomTypeController::class, 'destroySeasonalRate'])
            ->middleware('can_do:hotel_room_types.edit')
            ->name('room-types.seasonal.destroy');

        Route::get('packages', [PackageController::class, 'index'])
            ->middleware('can_do:hotel_packages.access')
            ->name('packages.index');
        Route::put('packages/{package}', [PackageController::class, 'update'])
            ->middleware('can_do:hotel_packages.edit')
            ->name('packages.update');

        Route::get('/', [RoomController::class, 'index'])
            ->middleware('can_do:hotel_rooms.access')
            ->name('rooms.index');
        Route::post('/', [RoomController::class, 'store'])
            ->middleware('can_do:hotel_rooms.create')
            ->name('rooms.store');
        Route::put('{room}', [RoomController::class, 'update'])
            ->middleware('can_do:hotel_rooms.edit')
            ->name('rooms.update');
        Route::put('{room}/status', [RoomController::class, 'updateStatus'])
            ->middleware('can_do:hotel_rooms.edit_status')
            ->name('rooms.update-status');
    });

    // ── Guests ────────────────────────────────────────────────────────────────
    Route::prefix('guests')->name('hotel.guests.')->group(function () {
        Route::get('/', [GuestController::class, 'index'])
            ->middleware('can_do:hotel_guests.access')
            ->name('index');
        Route::post('/', [GuestController::class, 'store'])
            ->middleware('can_do:hotel_guests.create')
            ->name('store');
        Route::get('{guest}', [GuestController::class, 'show'])
            ->middleware('can_do:hotel_guests.view')
            ->name('show');
        Route::put('{guest}', [GuestController::class, 'update'])
            ->middleware('can_do:hotel_guests.edit')
            ->name('update');
        Route::post('{guest}/loyalty-adjust', [GuestController::class, 'adjustLoyalty'])
            ->middleware('can_do:hotel_guests.loyalty_adjust')
            ->name('loyalty-adjust');
    });

    // ── Corporate Accounts ───────────────────────────────────────────────────
    Route::prefix('corporate')->name('hotel.corporate.')->group(function () {
        Route::get('/', [CorporateAccountController::class, 'index'])
            ->middleware('can_do:hotel_corporate.access')
            ->name('index');
        Route::post('/', [CorporateAccountController::class, 'store'])
            ->middleware('can_do:hotel_corporate.create')
            ->name('store');
        Route::put('{corporateAccount}', [CorporateAccountController::class, 'update'])
            ->middleware('can_do:hotel_corporate.edit')
            ->name('update');
        Route::get('{corporateAccount}/statement', [CorporateAccountController::class, 'statement'])
            ->middleware('can_do:hotel_corporate.access')
            ->name('statement');
        Route::post('{corporateAccount}/settle', [CorporateAccountController::class, 'settle'])
            ->middleware('can_do:hotel_corporate.edit')
            ->name('settle');
    });

    // ── Reservations ──────────────────────────────────────────────────────────
    Route::prefix('reservations')->name('hotel.reservations.')->group(function () {
        Route::get('availability', [ReservationController::class, 'availability'])
            ->middleware('can_do:hotel_reservations.access')
            ->name('availability');
        Route::get('calendar', [ReservationController::class, 'calendar'])
            ->middleware('can_do:hotel_reservations.access')
            ->name('calendar');
        Route::get('groups', [ReservationController::class, 'groups'])
            ->middleware('can_do:hotel_reservations.access')
            ->name('groups.index');
        Route::get('groups/{groupBooking}/invoice', [ReservationController::class, 'groupInvoice'])
            ->middleware('can_do:hotel_reservations.view')
            ->name('groups.invoice');

        Route::get('/', [ReservationController::class, 'index'])
            ->middleware('can_do:hotel_reservations.access')
            ->name('index');
        Route::post('/', [ReservationController::class, 'store'])
            ->middleware('can_do:hotel_reservations.create')
            ->name('store');

        Route::put('rooms/{reservationRoom}/bill-to', [ReservationController::class, 'billTo'])
            ->middleware('can_do:hotel_reservations.edit')
            ->name('rooms.bill-to');

        Route::get('{reservation}/checkout-quote', [ReservationController::class, 'checkoutQuote'])
            ->middleware('can_do:hotel_reservations.checkout')
            ->name('checkout-quote');
        Route::post('{reservation}/checkout', [ReservationController::class, 'checkout'])
            ->middleware('can_do:hotel_reservations.checkout')
            ->name('checkout');
        Route::post('{reservation}/check-in', [ReservationController::class, 'checkIn'])
            ->middleware('can_do:hotel_reservations.check_in')
            ->name('check-in');
        Route::post('{reservation}/cancel', [ReservationController::class, 'cancel'])
            ->middleware('can_do:hotel_reservations.cancel')
            ->name('cancel');
        Route::post('{reservation}/item-check', [ReservationController::class, 'itemCheck'])
            ->middleware('can_do:hotel_reservations.edit')
            ->name('item-check');

        Route::get('{reservation}', [ReservationController::class, 'show'])
            ->middleware('can_do:hotel_reservations.view')
            ->name('show');
        Route::put('{reservation}', [ReservationController::class, 'update'])
            ->middleware('can_do:hotel_reservations.edit')
            ->name('update');
    });

    // ── Folios ────────────────────────────────────────────────────────────────
    Route::prefix('folios')->name('hotel.folios.')->group(function () {
        Route::post('lines/{line}/void', [FolioController::class, 'voidLine'])
            ->middleware('can_do:hotel_folios.void_line')
            ->name('lines.void');

        Route::get('{folio}', [FolioController::class, 'show'])
            ->middleware('can_do:hotel_folios.view')
            ->name('show');
        Route::post('{folio}/lines', [FolioController::class, 'addLine'])
            ->middleware('can_do:hotel_folios.add_line')
            ->name('lines.store');
        Route::post('{folio}/payments', [FolioController::class, 'payment'])
            ->middleware('can_do:hotel_folios.payment')
            ->name('payments.store');
        Route::post('{folio}/refund', [FolioController::class, 'refund'])
            ->middleware('can_do:hotel_folios.refund')
            ->name('refund');

        // ── Branded invoice PDF — ?format=thermal|a4 (guest INV / venue VNU types) ──
        Route::get('{folio}/invoice', [FolioController::class, 'invoice'])
            ->middleware('can_do:hotel_folios.invoice')
            ->name('invoice');
    });

    // ── Menu (Categories + Items) ────────────────────────────────────────────
    Route::prefix('menu')->name('hotel.menu.')->group(function () {
        Route::get('full', [MenuItemController::class, 'full'])
            ->middleware('can_do:hotel_menu_items.access')
            ->name('full');

        Route::get('categories', [MenuCategoryController::class, 'index'])
            ->middleware('can_do:hotel_menu_categories.access')
            ->name('categories.index');
        Route::post('categories', [MenuCategoryController::class, 'store'])
            ->middleware('can_do:hotel_menu_categories.create')
            ->name('categories.store');
        Route::put('categories/{menuCategory}', [MenuCategoryController::class, 'update'])
            ->middleware('can_do:hotel_menu_categories.edit')
            ->name('categories.update');
        Route::delete('categories/{menuCategory}', [MenuCategoryController::class, 'destroy'])
            ->middleware('can_do:hotel_menu_categories.delete')
            ->name('categories.destroy');

        Route::get('items', [MenuItemController::class, 'index'])
            ->middleware('can_do:hotel_menu_items.access')
            ->name('items.index');
        Route::post('items', [MenuItemController::class, 'store'])
            ->middleware('can_do:hotel_menu_items.create')
            ->name('items.store');
        Route::put('items/{menuItem}', [MenuItemController::class, 'update'])
            ->middleware('can_do:hotel_menu_items.edit')
            ->name('items.update');
        Route::delete('items/{menuItem}', [MenuItemController::class, 'destroy'])
            ->middleware('can_do:hotel_menu_items.delete')
            ->name('items.destroy');
        Route::put('items/{menuItem}/sold-out', [MenuItemController::class, 'toggleSoldOut'])
            ->middleware('can_do:hotel_menu_items.sold_out')
            ->name('items.sold-out');
    });

    // ── Ingredients & Stock ───────────────────────────────────────────────────
    Route::prefix('ingredients')->name('hotel.ingredients.')->group(function () {
        Route::get('expiry', [IngredientController::class, 'expiry'])
            ->middleware('can_do:hotel_ingredients.access')
            ->name('expiry');
        Route::post('batches/{batch}/write-off', [IngredientController::class, 'writeOff'])
            ->middleware('can_do:hotel_ingredients.write_off')
            ->name('batches.write-off');

        Route::get('/', [IngredientController::class, 'index'])
            ->middleware('can_do:hotel_ingredients.access')
            ->name('index');
        Route::post('/', [IngredientController::class, 'store'])
            ->middleware('can_do:hotel_ingredients.create')
            ->name('store');
        Route::put('{ingredient}', [IngredientController::class, 'update'])
            ->middleware('can_do:hotel_ingredients.edit')
            ->name('update');
        Route::delete('{ingredient}', [IngredientController::class, 'destroy'])
            ->middleware('can_do:hotel_ingredients.delete')
            ->name('destroy');
        Route::post('{ingredient}/adjust', [IngredientController::class, 'adjustStock'])
            ->middleware('can_do:hotel_ingredients.adjust_stock')
            ->name('adjust');
    });

    // ── Orders (POS) ────────────────────────────────────────────────────────
    Route::prefix('orders')->name('hotel.orders.')->group(function () {
        Route::get('kot', [OrderController::class, 'kot'])
            ->middleware('can_do:hotel_orders.access')
            ->name('kot');

        Route::get('/', [OrderController::class, 'index'])
            ->middleware('can_do:hotel_orders.access')
            ->name('index');
        Route::post('/', [OrderController::class, 'store'])
            ->middleware('can_do:hotel_orders.create')
            ->name('store');

        Route::post('{order}/items', [OrderController::class, 'addItems'])
            ->middleware('can_do:hotel_orders.create')
            ->name('items.store');
        Route::post('{order}/items/{item}/void', [OrderController::class, 'voidItem'])
            ->middleware('can_do:hotel_orders.void_item')
            ->name('items.void');

        Route::put('{order}/kot', [OrderController::class, 'updateKotStatus'])
            ->middleware('can_do:hotel_orders.kot')
            ->name('kot.update');
        Route::put('{order}/park', [OrderController::class, 'park'])
            ->middleware('can_do:hotel_orders.hold')
            ->name('park');
        Route::put('{order}/resume', [OrderController::class, 'resume'])
            ->middleware('can_do:hotel_orders.hold')
            ->name('resume');
        Route::put('{order}/discount', [OrderController::class, 'discount'])
            ->middleware('can_do:hotel_orders.discount')
            ->name('discount');

        Route::post('{order}/settle', [OrderController::class, 'settle'])
            ->middleware('can_do:hotel_orders.settle')
            ->name('settle');
        Route::post('{order}/charge-to-room', [OrderController::class, 'chargeToRoom'])
            ->middleware('can_do:hotel_orders.charge_to_room')
            ->name('charge-to-room');
        Route::post('{order}/void', [OrderController::class, 'void'])
            ->middleware('can_do:hotel_orders.void')
            ->name('void');
        Route::post('{order}/refund', [OrderController::class, 'refund'])
            ->middleware('can_do:hotel_orders.refund')
            ->name('refund');

        // ── Printing: receipt (thermal/A4) + walk-in slip + KOT ticket ──
        Route::get('{order}/receipt', [OrderController::class, 'receipt'])
            ->middleware('can_do:hotel_orders.receipt')
            ->name('receipt');
        Route::get('{order}/slip', [OrderController::class, 'slip'])
            ->middleware('can_do:hotel_orders.slip')
            ->name('slip');
        Route::get('{order}/kot-ticket', [OrderController::class, 'kotTicket'])
            ->middleware('can_do:hotel_orders.kot_ticket')
            ->name('kot-ticket');

        Route::get('{order}', [OrderController::class, 'show'])
            ->middleware('can_do:hotel_orders.view')
            ->name('show');
    });

    // ── Housekeeping ────────────────────────────────────────────────────────
    Route::prefix('housekeeping')->name('hotel.housekeeping.')->group(function () {
        Route::get('tasks', [HousekeepingTaskController::class, 'index'])
            ->middleware('can_do:hotel_housekeeping.access')
            ->name('tasks.index');
        Route::post('tasks', [HousekeepingTaskController::class, 'store'])
            ->middleware('can_do:hotel_housekeeping.create')
            ->name('tasks.store');
        Route::put('tasks/{task}/assign', [HousekeepingTaskController::class, 'assign'])
            ->middleware('can_do:hotel_housekeeping.assign')
            ->name('tasks.assign');
        Route::put('tasks/{task}/checklist', [HousekeepingTaskController::class, 'updateChecklist'])
            ->middleware('can_do:hotel_housekeeping.checklist')
            ->name('tasks.checklist');
        Route::post('tasks/{task}/complete', [HousekeepingTaskController::class, 'complete'])
            ->middleware('can_do:hotel_housekeeping.complete')
            ->name('tasks.complete');
    });

    // ── Maintenance ───────────────────────────────────────────────────────────
    Route::prefix('maintenance')->name('hotel.maintenance.')->group(function () {
        Route::get('/', [MaintenanceIssueController::class, 'index'])
            ->middleware('can_do:hotel_maintenance.access')
            ->name('index');
        // Lightweight venue picker for the "log issue" form — deliberately
        // ungated beyond auth, matching Node's coarse role model where any
        // operational staff (Housekeeper/Chef/Security, none of whom hold
        // hotel_venues.access) could log an issue against a venue.
        Route::get('venue-options', [MaintenanceIssueController::class, 'venueOptions'])
            ->middleware('can_do:hotel_maintenance.access')
            ->name('venue-options');
        Route::post('/', [MaintenanceIssueController::class, 'store'])
            ->middleware('can_do:hotel_maintenance.create')
            ->name('store');
        Route::put('{issue}', [MaintenanceIssueController::class, 'update'])
            ->middleware('can_do:hotel_maintenance.edit')
            ->name('update');
    });

    // ── Laundry ───────────────────────────────────────────────────────────────
    Route::prefix('laundry')->name('hotel.laundry.')->group(function () {
        Route::get('items', [LaundryController::class, 'index'])
            ->middleware('can_do:hotel_laundry.access')
            ->name('items.index');
        Route::post('items', [LaundryController::class, 'store'])
            ->middleware('can_do:hotel_laundry.create')
            ->name('items.store');
        Route::put('items/{laundryItem}', [LaundryController::class, 'update'])
            ->middleware('can_do:hotel_laundry.edit')
            ->name('items.update');
        Route::post('charge', [LaundryController::class, 'charge'])
            ->middleware('can_do:hotel_laundry.charge')
            ->name('charge');
    });

    // ── Venues ────────────────────────────────────────────────────────────────
    Route::prefix('venues')->name('hotel.venues.')->group(function () {
        Route::get('/', [VenueController::class, 'index'])
            ->middleware('can_do:hotel_venues.access')
            ->name('index');
        Route::put('{venue}', [VenueController::class, 'update'])
            ->middleware('can_do:hotel_venues.edit')
            ->name('update');
        Route::get('{venue}/calendar', [VenueController::class, 'calendar'])
            ->middleware('can_do:hotel_venues.access')
            ->name('calendar');

        Route::get('bookings/list', [VenueBookingController::class, 'index'])
            ->middleware('can_do:hotel_venue_bookings.access')
            ->name('bookings.index');
        Route::post('bookings', [VenueBookingController::class, 'store'])
            ->middleware('can_do:hotel_venue_bookings.create')
            ->name('bookings.store');
        Route::get('bookings/{booking}', [VenueBookingController::class, 'show'])
            ->middleware('can_do:hotel_venue_bookings.view')
            ->name('bookings.show');
        Route::put('bookings/{booking}', [VenueBookingController::class, 'update'])
            ->middleware('can_do:hotel_venue_bookings.edit')
            ->name('bookings.update');
        Route::post('bookings/{booking}/confirm', [VenueBookingController::class, 'confirm'])
            ->middleware('can_do:hotel_venue_bookings.confirm')
            ->name('bookings.confirm');
        Route::post('bookings/{booking}/complete', [VenueBookingController::class, 'complete'])
            ->middleware('can_do:hotel_venue_bookings.complete')
            ->name('bookings.complete');
        Route::post('bookings/{booking}/cancel', [VenueBookingController::class, 'cancel'])
            ->middleware('can_do:hotel_venue_bookings.cancel')
            ->name('bookings.cancel');
    });

    // ── Shifts ────────────────────────────────────────────────────────────────
    Route::prefix('shifts')->name('hotel.shifts.')->group(function () {
        Route::get('current', [ShiftController::class, 'current'])
            ->middleware('can_do:hotel_shifts.access')
            ->name('current');
        Route::post('open', [ShiftController::class, 'open'])
            ->middleware('can_do:hotel_shifts.open')
            ->name('open');
        Route::post('{shift}/close', [ShiftController::class, 'close'])
            ->middleware('can_do:hotel_shifts.close')
            ->name('close');
        Route::get('/', [ShiftController::class, 'index'])
            ->middleware('can_do:hotel_shifts.access')
            ->name('index');
    });

    // ── Attendance ────────────────────────────────────────────────────────────
    Route::prefix('attendance')->name('hotel.attendance.')->group(function () {
        Route::post('clock-in', [AttendanceController::class, 'clockIn'])
            ->middleware('can_do:hotel_attendance.access')
            ->name('clock-in');
        Route::post('clock-out', [AttendanceController::class, 'clockOut'])
            ->middleware('can_do:hotel_attendance.access')
            ->name('clock-out');
        Route::get('me', [AttendanceController::class, 'me'])
            ->middleware('can_do:hotel_attendance.access')
            ->name('me');
        Route::get('on-duty', [AttendanceController::class, 'onDuty'])
            ->middleware('can_do:hotel_attendance.on_duty')
            ->name('on-duty');
        Route::get('export', [AttendanceController::class, 'export'])
            ->middleware('can_do:hotel_attendance.export')
            ->name('export');
        Route::get('/', [AttendanceController::class, 'index'])
            ->middleware('can_do:hotel_attendance.view_all')
            ->name('index');
    });

    // ── Payroll (Owner-only) ────────────────────────────────────────────────────
    Route::prefix('payroll')->name('hotel.payroll.')->group(function () {
        Route::get('staff-pay', [PayrollController::class, 'staffPay'])
            ->middleware('can_do:hotel_payroll.manage_pay')
            ->name('staff-pay.index');
        Route::put('staff-pay/{user}', [PayrollController::class, 'updateStaffPay'])
            ->middleware('can_do:hotel_payroll.manage_pay')
            ->name('staff-pay.update');

        Route::get('runs', [PayrollController::class, 'runs'])
            ->middleware('can_do:hotel_payroll.view')
            ->name('runs.index');
        Route::post('runs', [PayrollController::class, 'generateRun'])
            ->middleware('can_do:hotel_payroll.generate')
            ->name('runs.store');
        Route::get('runs/{run}', [PayrollController::class, 'showRun'])
            ->middleware('can_do:hotel_payroll.view')
            ->name('runs.show');
        Route::delete('runs/{run}', [PayrollController::class, 'deleteRun'])
            ->middleware('can_do:hotel_payroll.delete_run')
            ->name('runs.destroy');
        Route::post('runs/{run}/finalize', [PayrollController::class, 'finalizeRun'])
            ->middleware('can_do:hotel_payroll.finalize')
            ->name('runs.finalize');
        Route::get('runs/{run}/export', [PayrollController::class, 'exportRun'])
            ->middleware('can_do:hotel_payroll.export')
            ->name('runs.export');

        Route::put('lines/{line}', [PayrollController::class, 'updateLine'])
            ->middleware('can_do:hotel_payroll.adjust_line')
            ->name('lines.update');
        Route::post('lines/{line}/mark-paid', [PayrollController::class, 'markLinePaid'])
            ->middleware('can_do:hotel_payroll.mark_paid')
            ->name('lines.mark-paid');

        // ── Branded payslip PDF (A4) ──
        Route::get('lines/{line}/payslip', [PayrollController::class, 'payslip'])
            ->middleware('can_do:hotel_payroll.payslip')
            ->name('lines.payslip');
    });

    // ── Visitors ──────────────────────────────────────────────────────────────
    Route::prefix('visitors')->name('hotel.visitors.')->group(function () {
        Route::get('/', [VisitorLogController::class, 'index'])
            ->middleware('can_do:hotel_visitors.access')
            ->name('index');
        Route::post('/', [VisitorLogController::class, 'store'])
            ->middleware('can_do:hotel_visitors.create')
            ->name('store');
        Route::post('{visitor}/out', [VisitorLogController::class, 'signOut'])
            ->middleware('can_do:hotel_visitors.sign_out')
            ->name('sign-out');
    });

    // ── Notifications ────────────────────────────────────────────────────────
    Route::prefix('notifications')->name('hotel.notifications.')->group(function () {
        Route::post('test', [NotificationController::class, 'test'])
            ->middleware('can_do:hotel_notifications.test')
            ->name('test');
        Route::get('/', [NotificationController::class, 'index'])
            ->middleware('can_do:hotel_notifications.access')
            ->name('index');
        Route::post('run-scheduled', [NotificationController::class, 'runScheduled'])
            ->middleware('can_do:hotel_notifications.run_scheduled')
            ->name('run-scheduled');
    });

    // ── Reports + Night Audit ────────────────────────────────────────────────
    Route::prefix('reports')->name('hotel.reports.')->group(function () {
        Route::get('dashboard', [ReportController::class, 'dashboard'])
            ->middleware('can_do:hotel_reports.dashboard')
            ->name('dashboard');

        Route::get('daily', [ReportController::class, 'daily'])
            ->middleware('can_do:hotel_reports.daily')
            ->name('daily');
        Route::get('daily/pdf', [ReportController::class, 'dailyPdf'])
            ->middleware('can_do:hotel_reports.daily')
            ->name('daily.pdf');

        Route::post('night-audit/run', [ReportController::class, 'runNightAudit'])
            ->middleware('can_do:hotel_reports.night_audit_run')
            ->name('night-audit.run');
        Route::get('night-audit', [ReportController::class, 'nightAuditIndex'])
            ->middleware('can_do:hotel_reports.night_audit_view')
            ->name('night-audit.index');
        Route::get('night-audit/{nightAudit}/pdf', [ReportController::class, 'nightAuditPdf'])
            ->middleware('can_do:hotel_reports.night_audit_view')
            ->name('night-audit.pdf');

        Route::get('monthly', [ReportController::class, 'monthly'])
            ->middleware('can_do:hotel_reports.monthly')
            ->name('monthly');
        Route::get('monthly/pdf', [ReportController::class, 'monthlyPdf'])
            ->middleware('can_do:hotel_reports.monthly')
            ->name('monthly.pdf');

        Route::get('pos', [ReportController::class, 'pos'])
            ->middleware('can_do:hotel_reports.pos')
            ->name('pos');
        Route::get('pos/pdf', [ReportController::class, 'posPdf'])
            ->middleware('can_do:hotel_reports.pos')
            ->name('pos.pdf');
    });

    // ── Staff (PIN quick-unlock; CRUD lives in User Management) ────────────────
    Route::prefix('staff')->name('hotel.staff.')->group(function () {
        // Ungated beyond auth — a lightweight directory for "assign to" pickers.
        Route::get('/', [StaffController::class, 'index'])->name('index');
        Route::put('{user}/pin', [StaffController::class, 'setPin'])
            ->middleware('can_do:hotel_staff.set_pin')
            ->name('pin.update');
    });

});
