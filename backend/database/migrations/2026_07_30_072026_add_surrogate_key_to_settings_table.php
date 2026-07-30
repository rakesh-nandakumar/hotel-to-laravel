<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `settings.key` was the primary key (Setting.php, coding_principles.md §3
     * — a stable business identifier, not a surrogate id). That no longer
     * holds now every tenant can have its own "hotel.name", "billing.vat_pct",
     * etc. — the same key must exist once per tenant, so the table needs a
     * real surrogate id and a (tenant_id, key) composite unique instead.
     *
     * Rebuilt via a fresh table + copy rather than an in-place PK swap:
     * MySQL/MariaDB doesn't support dropping a PRIMARY KEY and adding an
     * AUTO_INCREMENT column in one clean ALTER the way SQLite's rebuild-based
     * schema builder tolerates, so this takes the portable route.
     *
     * Two things that only surface on real MySQL/MariaDB (SQLite is lenient
     * about both, so a rebuild that skipped them still passed on the test
     * suite): `key` is a reserved word and needs backtick-quoting in the raw
     * INSERT/SELECT below, and `value`/`hint` must keep the longText/text
     * widths the two earlier migrations (2026_07_18_053420, 2026_07_19_050644)
     * already gave them — SQLite doesn't enforce column-length limits, so this
     * would only fail once a real setting value (e.g. a base64 logo) exceeded
     * a narrower default type on MySQL.
     */
    public function up(): void
    {
        Schema::create('settings_new', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('key');
            $table->longText('value')->nullable();
            $table->string('type')->default('text');
            $table->string('category')->index();
            $table->string('label');
            $table->text('hint')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        DB::statement(
            'INSERT INTO settings_new (tenant_id, `key`, value, type, category, label, hint, updated_by, created_at, updated_at) '.
            'SELECT tenant_id, `key`, value, type, category, label, hint, updated_by, created_at, updated_at FROM settings'
        );

        Schema::drop('settings');
        Schema::rename('settings_new', 'settings');
    }

    public function down(): void
    {
        Schema::create('settings_old', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->text('value')->nullable();
            $table->string('type')->default('text');
            $table->string('category')->index();
            $table->string('label');
            $table->text('hint')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement(
            'INSERT INTO settings_old (tenant_id, `key`, value, type, category, label, hint, updated_by, created_at, updated_at) '.
            'SELECT tenant_id, `key`, value, type, category, label, hint, updated_by, created_at, updated_at FROM settings'
        );

        Schema::drop('settings');
        Schema::rename('settings_old', 'settings');
    }
};
