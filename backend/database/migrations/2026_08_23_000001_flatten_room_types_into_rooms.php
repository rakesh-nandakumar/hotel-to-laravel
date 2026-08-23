<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Add new columns to rooms that previously lived on room_types ──
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('name')->nullable()->after('number')->comment('Room category / type label (e.g. Deluxe, Family)');
            $table->unsignedInteger('max_occupancy')->default(2)->after('name');
            $table->string('bed_config')->nullable()->after('max_occupancy');
            $table->unsignedInteger('weekday_rate')->default(0)->after('bed_config')->comment('LKR cents/night');
            $table->unsignedInteger('weekend_rate')->default(0)->after('weekday_rate')->comment('LKR cents/night');
            $table->json('item_checklist')->nullable()->after('amenities')->comment('Check-in/out item verification template (per-room)');
            $table->json('cleaning_checklist')->nullable()->after('item_checklist')->comment('Housekeeping task template (per-room)');
        });

        // ── 2. Make room_type_id nullable so new rooms can be created without a type ──
        // Drop FK, make nullable, re-add with nullOnDelete (so old types can be removed safely).
        try {
            Schema::table('rooms', function (Blueprint $table) {
                $table->dropForeign(['room_type_id']);
            });
        } catch (Throwable $e) {
            // FK name may differ on SQLite (tests) — ignore.
        }

        // SQLite does not support altering column nullability via modify(); use raw where needed.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `rooms` MODIFY `room_type_id` BIGINT UNSIGNED NULL');
        } else {
            // On SQLite the column is already nullable-compatible for tests; no-op.
            // The actual schema change is handled by the FK drop above; inserts with NULL will succeed.
        }

        try {
            Schema::table('rooms', function (Blueprint $table) {
                $table->foreign('room_type_id')->references('id')->on('room_types')->nullOnDelete();
            });
        } catch (Throwable $e) {
            // Ignore if FK already exists or SQLite limitation.
        }

        // ── 3. Extend seasonal_rates to optionally belong to a room instead of a type ──
        // Base migration (2026_07_14) now already creates room_id as nullable, so this step is idempotent:
        // only add the column/index if a pre-upgrade production DB is being migrated.
        if (! Schema::hasColumn('seasonal_rates', 'room_id')) {
            Schema::table('seasonal_rates', function (Blueprint $table) {
                try {
                    $table->dropForeign(['room_type_id']);
                } catch (Throwable $e) {
                }
                if (DB::getDriverName() !== 'sqlite') {
                    DB::statement('ALTER TABLE `seasonal_rates` MODIFY `room_type_id` BIGINT UNSIGNED NULL');
                }
                $table->foreignId('room_id')->nullable()->after('room_type_id')->constrained('rooms')->cascadeOnDelete();
                $table->index(['room_id', 'start_date', 'end_date']);
                try {
                    $table->foreign('room_type_id')->references('id')->on('room_types')->cascadeOnDelete();
                } catch (Throwable $e) {
                }
            });
        } else {
            // Ensure the composite index exists even on fresh DBs where the column was already present
            try {
                Schema::table('seasonal_rates', function (Blueprint $table) {
                    $table->index(['room_id', 'start_date', 'end_date']);
                });
            } catch (Throwable $e) {
                // Index already exists — ignore
            }
        }

        // ── 4. Backfill: copy room_type fields into each room that has one ──
        // Use raw SQL that works on both MySQL and SQLite.
        $rooms = DB::table('rooms')->whereNotNull('room_type_id')->get(['id', 'room_type_id']);
        foreach ($rooms as $room) {
            $type = DB::table('room_types')->where('id', $room->room_type_id)->first();
            if (! $type) {
                continue;
            }
            DB::table('rooms')->where('id', $room->id)->update([
                'name' => $type->name,
                'max_occupancy' => $type->max_occupancy,
                'bed_config' => $type->bed_config,
                'weekday_rate' => $type->weekday_rate,
                'weekend_rate' => $type->weekend_rate,
                'item_checklist' => $type->item_checklist,
                'cleaning_checklist' => $type->cleaning_checklist,
            ]);
        }

        // ── 5. Backfill: duplicate each seasonal_rate per room of that type ──
        // Original type-level rates stay (room_type_id still set); new per-room clones get room_id.
        $rates = DB::table('seasonal_rates')->whereNotNull('room_type_id')->whereNull('room_id')->get();
        foreach ($rates as $rate) {
            $roomIds = DB::table('rooms')->where('room_type_id', $rate->room_type_id)->pluck('id');
            foreach ($roomIds as $roomId) {
                // Skip if clone already exists (idempotent re-run)
                $exists = DB::table('seasonal_rates')
                    ->where('room_id', $roomId)
                    ->where('name', $rate->name)
                    ->where('start_date', $rate->start_date)
                    ->where('end_date', $rate->end_date)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('seasonal_rates')->insert([
                    'tenant_id' => $rate->tenant_id,
                    'room_type_id' => null,
                    'room_id' => $roomId,
                    'name' => $rate->name,
                    'start_date' => $rate->start_date,
                    'end_date' => $rate->end_date,
                    'rate' => $rate->rate,
                    'created_by' => $rate->created_by,
                    'updated_by' => $rate->updated_by,
                    'created_at' => $rate->created_at,
                    'updated_at' => $rate->updated_at,
                ]);
            }
        }

        // ── 6. Give rooms that had no type (or pre-existing NULL) sensible defaults ──
        DB::table('rooms')->whereNull('name')->update(['name' => DB::raw("COALESCE(name, 'Standard')")]);
        // weekday/weekend already default 0; item_checklists stay null until edited.
    }

    public function down(): void
    {
        // Seasonal rates: only drop the column/index if this migration added them
        // (i.e. on a production DB that was upgraded from a version without room_id).
        // On fresh installs the base migration already includes room_id, so we leave it.
        // We detect this by checking if the migration's own add-column would have been needed.
        // For simplicity we just attempt to drop the index; the column itself is kept
        // when it originates from the base migration (fresh DBs).
        try {
            Schema::table('seasonal_rates', function (Blueprint $table) {
                $table->dropIndex(['room_id', 'start_date', 'end_date']);
            });
        } catch (Throwable $e) {
        }

        // Re-make room_type_id non-nullable (best effort) — only for MySQL where
        // the original schema was NOT NULL. Fresh DBs already have it nullable via
        // the base migration, so this is a no-op there.
        if (DB::getDriverName() !== 'sqlite') {
            try {
                Schema::table('rooms', function (Blueprint $table) {
                    $table->dropForeign(['room_type_id']);
                });
            } catch (Throwable $e) {
            }
            // Only revert if the column is currently nullable; on fresh DBs it's already nullable
            // so this MODIFY is harmless but we keep it for upgraded DBs.
            try {
                DB::statement('ALTER TABLE `rooms` MODIFY `room_type_id` BIGINT UNSIGNED NOT NULL');
                Schema::table('rooms', function (Blueprint $table) {
                    $table->foreign('room_type_id')->references('id')->on('room_types')->restrictOnDelete();
                });
            } catch (Throwable $e) {
            }
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['name', 'max_occupancy', 'bed_config', 'weekday_rate', 'weekend_rate', 'item_checklist', 'cleaning_checklist']);
        });
    }
};
