<?php

namespace App\Http\Pagination;

/**
 * Page size for the media library: the same choices as every other list, with
 * a larger default because the grid shows several files per row.
 */
class MediaPerPage extends PerPage
{
    public const DEFAULT = 25;
}
