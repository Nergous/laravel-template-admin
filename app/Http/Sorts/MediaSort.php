<?php

namespace App\Http\Sorts;

/**
 * Сортировка для списка медиа.
 *
 * По умолчанию сортирует по дате загрузки (created_at).
 */
class MediaSort extends Sort
{
    protected array $allowedSorts = ['id', 'original_name', 'created_at'];

    protected string $defaultSort = 'created_at';
}
