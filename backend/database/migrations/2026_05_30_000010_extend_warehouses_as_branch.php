<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `warehouses` is the legacy table-name kept under decision D3; UI labels it
 * "Branch" everywhere. This migration adds the columns the SRS Branch entity
 * needs (manager_user_id, deleted_by) without renaming the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouses', 'manager_user_id')) {
                $table->foreignId('manager_user_id')
                    ->nullable()
                    ->after('country')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('warehouses', 'deleted_by')) {
                $table->foreignId('deleted_by')
                    ->nullable()
                    ->after('updated_by')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            if (Schema::hasColumn('warehouses', 'manager_user_id')) {
                $table->dropConstrainedForeignId('manager_user_id');
            }
            if (Schema::hasColumn('warehouses', 'deleted_by')) {
                $table->dropConstrainedForeignId('deleted_by');
            }
        });
    }
};
