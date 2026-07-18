<?php

namespace App\Support\Lookups;

/**
 * Fixed, non-business, internal type discriminator for Setting values —
 * a legitimate use of a plain constants class rather than a lookup table
 * (coding_principles.md §2: "Use enums only when values are genuinely static").
 */
class SettingType
{
    public const TEXT = 'text';

    public const NUMBER = 'number';

    public const PERCENT = 'percent';

    public const MONEY = 'money';

    public const BOOLEAN = 'boolean';

    public const JSON = 'json';

    public const TIME = 'time';

    public const IMAGE = 'image';
}
