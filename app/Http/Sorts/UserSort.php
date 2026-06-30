<?php

namespace App\Http\Sorts;

/**
 * Сортировка для списка пользователей.
 *
 * По умолчанию сортирует по id (наследуется от Sort).
 */
class UserSort extends Sort
{
    protected array $allowedSorts = ['id', 'name', 'email', 'created_at', 'deleted_at'];
}
