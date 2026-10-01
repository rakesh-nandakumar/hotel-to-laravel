<?php

namespace App\Console\Commands;

use App\Models\TenantDataPurge;
use App\Services\Tenancy\DataPurge\PurgeBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneTenantDataBackups extends Command
{
    protected $signature = 'tenant-data:prune-backups';

    protected $description = 'Delete data-purge backups that passed their retention date, and backup files no purge points to';

    public function handle(): int
    {
        $this->info("Pruned {$this->pruneExpired()} expired backup(s).");

        $orphans = $this->pruneOrphans();

        if ($orphans > 0) {
            $this->info("Removed {$orphans} orphaned backup file(s).");
        }

        return self::SUCCESS;
    }

    private function pruneExpired(): int
    {
        $pruned = 0;

        TenantDataPurge::query()
            ->whereNotNull('backup_path')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->each(function (TenantDataPurge $purge) use (&$pruned): void {
                PurgeBackup::delete($purge->backup_path);

                $purge->update([
                    'backup_path' => null,
                    // A restored purge keeps saying so; only an unused one "expires".
                    'status' => $purge->status === TenantDataPurge::STATUS_COMPLETED
                        ? TenantDataPurge::STATUS_EXPIRED
                        : $purge->status,
                ]);

                $pruned++;
            });

        return $pruned;
    }

    /**
     * A purge killed part-way (host timeout, crash) rolls its database work
     * back but leaves its half-written backup behind, with no history row to
     * ever expire it. A day's grace keeps a purge that is still running safe.
     */
    private function pruneOrphans(): int
    {
        $disk = Storage::disk(config('tenancy-purge.backup_disk'));
        $referenced = TenantDataPurge::query()->whereNotNull('backup_path')->pluck('backup_path')->flip();
        $cutoff = now()->subDay()->getTimestamp();
        $removed = 0;

        foreach ($disk->allFiles(config('tenancy-purge.backup_directory')) as $file) {
            if ($referenced->has($file) || $disk->lastModified($file) > $cutoff) {
                continue;
            }

            $disk->delete($file);
            $removed++;
        }

        return $removed;
    }
}
