<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Admin accent color preset. Values match the design-token set keys in
 * resources/js/admin/accents.css.
 */
enum AccentPreset: string
{
    use HasLabel;

    case Blue = 'blue';
    case Indigo = 'indigo';
    case Violet = 'violet';
    case Fuchsia = 'fuchsia';
    case Rose = 'rose';
    case Orange = 'orange';
    case Green = 'green';
    case Teal = 'teal';
    case Graphite = 'graphite';
}
