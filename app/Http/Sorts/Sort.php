<?php

namespace App\Http\Sorts;

use Illuminate\Http\Request;

/**
 * Базовый класс сортировки для списков.
 *
 * Читает параметры sort и direction из GET-запроса и валидирует их
 * против списка допустимых полей. Если переданное значение недопустимо —
 * используется значение по умолчанию.
 *
 * Наследники переопределяют $allowedSorts и при необходимости $defaultSort.
 *
 * Использование в контроллере (Inertia):
 *   public function index(Request $request, UserSort $sort)
 *   {
 *       $query->orderBy($sort->getSort(), $sort->getDirection());
 *       return Inertia::render('Users/Index', [...$sort->toArray()]);
 *   }
 *
 * Параметры приходят query-строкой:
 *   ?sort=title&direction=asc
 */
class Sort
{
    protected array $allowedSorts = [];

    protected string $defaultSort = 'id';

    /**
     * Направление по умолчанию (asc|desc) — единственный источник истины.
     * Наследники переопределяют при необходимости.
     */
    protected string $defaultDirection = 'desc';

    protected string $sort;

    protected string $direction;

    public function __construct(Request $request)
    {
        $this->sort = in_array($request->sort, $this->allowedSorts)
            ? $request->sort
            : $this->defaultSort;

        $this->direction = in_array($request->direction, ['asc', 'desc'], true)
            ? $request->direction
            : $this->defaultDirection;
    }

    /**
     * Текущее поле сортировки.
     */
    public function getSort(): string
    {
        return $this->sort;
    }

    /**
     * Текущее направление сортировки (asc|desc).
     */
    public function getDirection(): string
    {
        return $this->direction;
    }

    /**
     * Возвращает текущие параметры сортировки для передачи в Inertia-ответ (props).
     *
     * @return array{currentSort: string, currentDirection: string}
     */
    public function toArray(): array
    {
        return [
            'currentSort' => $this->sort,
            'currentDirection' => $this->direction,
        ];
    }
}
