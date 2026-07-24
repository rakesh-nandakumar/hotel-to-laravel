<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apartment_ledgers', function (Blueprint $table) {
            // A ledger belongs to exactly one of booking/lease/sale — see the
            // booking_id column comment on the create-table migration.
            $table->foreignId('lease_id')->nullable()->unique()->after('booking_id')
                ->constrained('apartment_leases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('apartment_ledgers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lease_id');
        });
    }
};
