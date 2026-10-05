<?php

namespace App\Http\Sorts;

class RoleSort extends Sort
{
    protected array $allowedSorts = ['id', 'name', 'is_system', 'users_count', 'permissions_count', 'created_at'];

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';
}
