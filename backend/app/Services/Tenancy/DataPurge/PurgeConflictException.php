<?php

namespace App\Services\Tenancy\DataPurge;

use RuntimeException;

/**
 * The world changed between preview and execution (data edited, another purge
 * running). Nothing was changed; the operator should preview again. Mapped to
 * HTTP 409.
 */
class PurgeConflictException extends RuntimeException {}
