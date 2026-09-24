<?php

namespace App\Http\Pagination;

/**
 * Page size for the media library grid: multiples of the common column counts
 * (2, 3, 4, 6, 8), so full pages have no ragged last row.
 */
class MediaPerPage extends PerPage
{
    /** @var array<int, int> */
    public const OPTIONS = [24, 48, 96];

    public const DEFAULT = 24;
}
