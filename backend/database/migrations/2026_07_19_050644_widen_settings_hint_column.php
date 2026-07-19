<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The APIT tax bracket setting's hint explains a multi-clause formula and
 * exceeds the 255-char `string` column limit, so it needs `text` like
 * `settings.value` already got in 2026_07_18_053420.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('hint')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('hint')->nullable()->change();
        });
    }
};
