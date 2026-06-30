<?php

namespace App\Http\Sorts;

/**
 * Sort for the users list.
 *
 * By default sorts by id (inherited from Sort).
 */
class UserSort extends Sort
{
    protected array $allowedSorts = ['id', 'name', 'email', 'created_at', 'deleted_at'];
}
