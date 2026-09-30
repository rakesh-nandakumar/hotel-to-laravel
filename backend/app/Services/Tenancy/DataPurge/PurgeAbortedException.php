<?php

namespace App\Services\Tenancy\DataPurge;

use RuntimeException;

/**
 * The purge/restore refused to run because continuing would be unsafe (a
 * protected table was reached, another tenant's row is involved, the backup is
 * unusable…). Nothing was changed. The message is written for an operator.
 */
class PurgeAbortedException extends RuntimeException {}
