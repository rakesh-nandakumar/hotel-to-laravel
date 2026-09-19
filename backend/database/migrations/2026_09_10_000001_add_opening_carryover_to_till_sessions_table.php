<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The carry-over side of an open, recorded the same way the close already
     * records its own reconciliation (expected_cash/variance).
     *
     * The last closing count is only ever a *suggestion* for the next open —
     * the drawer is counted fresh, and the operator may legitimately open with
     * a different amount. Storing what was carried over alongside what was
     * actually opened with makes that decision auditable after the fact,
     * instead of it being reconstructable only from the OPENING_BALANCE
     * movement's free-text reason.
     */
    public function up(): void
    {
        Schema::table('till_sessions', function (Blueprint $table) {
            $table->unsignedInteger('carried_opening_cash')->nullable()->after('opening_cash')
                ->comment('the till\'s last closing count at the moment this session opened — null if the till had never been closed');
            $table->integer('opening_variance')->nullable()->after('carried_opening_cash')
                ->comment('opening_cash - carried_opening_cash; null when there was nothing to carry over');
            $table->text('opening_reason')->nullable()->after('opening_variance')
                ->comment('why the operator opened with an amount other than the carried-over balance');
        });
    }

    public function down(): void
    {
        Schema::table('till_sessions', function (Blueprint $table) {
            $table->dropColumn(['carried_opening_cash', 'opening_variance', 'opening_reason']);
        });
    }
};
