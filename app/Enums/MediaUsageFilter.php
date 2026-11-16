<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Media library usage filter: files referenced somewhere (see MediaUsage),
 * the rest, and images without alt text.
 */
enum MediaUsageFilter: string
{
    use HasLabel;

    case Used = 'used';
    case Unused = 'unused';
    case NoAlt = 'no_alt';
}
