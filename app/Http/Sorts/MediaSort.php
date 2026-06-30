<?php

namespace App\Http\Sorts;

/**
 * Sort for the media list.
 *
 * By default sorts by upload date (created_at).
 */
class MediaSort extends Sort
{
    protected array $allowedSorts = ['id', 'original_name', 'created_at'];

    protected string $defaultSort = 'created_at';
}
