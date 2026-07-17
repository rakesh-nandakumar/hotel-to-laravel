<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the materialised `user_permissions` copy with a thin per-user
     * exception layer. Effective permissions are now computed at read time:
     *   roles' permissions + allow overrides − deny overrides.
     */
    public function up(): void
    {
        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->string('type', 10); // allow | deny
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->primary(['user_id', 'permission_id']);
            $table->index(['user_id', 'type']);
        });

        // Legacy "direct" grants become explicit allow overrides; "role" copies are
        // dropped (they are now derived from the role automatically).
        if (Schema::hasTable('user_permissions')) {
            DB::table('user_permissions')->where('source', 'direct')->orderBy('user_id')->get()
                ->each(function ($row) {
                    DB::table('user_permission_overrides')->insertOrIgnore([
                        'user_id' => $row->user_id,
                        'permission_id' => $row->permission_id,
                        'type' => 'allow',
                        'granted_by' => $row->granted_by,
                        'granted_at' => $row->granted_at,
                    ]);
                });

            Schema::drop('user_permissions');
        }
    }

    public function down(): void
    {
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->string('source', 20);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->primary(['user_id', 'permission_id']);
            $table->index(['user_id', 'source']);
        });

        if (Schema::hasTable('user_permission_overrides')) {
            DB::table('user_permission_overrides')->where('type', 'allow')->get()->each(function ($row) {
                DB::table('user_permissions')->insertOrIgnore([
                    'user_id' => $row->user_id,
                    'permission_id' => $row->permission_id,
                    'source' => 'direct',
                    'granted_by' => $row->granted_by,
                    'granted_at' => $row->granted_at ?? now(),
                ]);
            });

            Schema::drop('user_permission_overrides');
        }
    }
};
