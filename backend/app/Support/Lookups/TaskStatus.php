<?php

namespace App\Support\Lookups;

/** Generic small task lifecycle — used by Housekeeping tasks. */
class TaskStatus
{
    public const PENDING = 'pending';

    public const IN_PROGRESS = 'in_progress';

    public const DONE = 'done';
}
