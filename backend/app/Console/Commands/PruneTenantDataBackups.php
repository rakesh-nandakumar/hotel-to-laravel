<?php

namespace App\Console\Commands;

use App\Models\TenantDataPurge;
use App\Services\Tenancy\DataPurge\PurgeBackup;
use Illuminate\Console\Command;

class PruneTenantDataBackups extends Command
{
    protected $signature = 'tenant-data:prune-backups';

    protected $description = 'Delete data-purge backups that passed their retention date and mark those purges expired';

    public function handle(): int
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

        $this->info("Pruned {$pruned} expired backup(s).");

        return self::SUCCESS;
    }
}
