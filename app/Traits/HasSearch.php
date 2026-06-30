<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Простой подстрочный (substring) поиск по нескольким колонкам через LIKE.
 *
 * Семантика — «колонка содержит подстроку». Регистрозависимость определяется
 * коллацией СУБД (MySQL/SQLite обычно case-insensitive для ASCII, Postgres —
 * case-sensitive). Wildcard'ы в запросе экранируются, поэтому "50%" ищется
 * буквально, а не как шаблон.
 *
 * Подключается к моделям через use HasSearch; модель задаёт колонки в своём
 * scopeSearch(). Используется в моделях: User, Media.
 *
 * Масштаб: LIKE '%...%' не использует B-tree индекс. Если поиск станет узким
 * местом — на PostgreSQL включите расширение pg_trgm + GIN-индекс
 * (gin_trgm_ops) под ILIKE; на MySQL — FULLTEXT-индекс (MATCH … AGAINST).
 *
 * ВНИМАНИЕ: это не drop-in замена. Только pg_trgm сохраняет семантику
 * «содержит подстроку». FULLTEXT/MATCH AGAINST ищет по словным токенам, а не
 * по подстроке: не находит середину строки, имеет минимальную длину токена
 * (InnoDB innodb_ft_min_token_size = 3, т.е. 2-символьные запросы вернут
 * пустоту) и ломает поиск по email (@ и . — разделители токенов). SQLite не
 * поддерживает ни то, ни другое — там поиск обязан остаться на LIKE. Включать
 * только под конкретный одиночный драйвер и с пересмотром UX поиска.
 */
trait HasSearch
{
    /**
     * Фильтрует записи по подстроке в любой из указанных колонок.
     *
     * @param  string|null  $search  Поисковая строка (пустая — запрос без изменений)
     * @param  string[]  $columns  Колонки для поиска
     */
    public function scopeSearchLike(Builder $query, ?string $search, array $columns): Builder
    {
        if (blank($search)) {
            return $query;
        }

        // Экранируем спецсимволы LIKE, чтобы "%" и "_" искались буквально.
        $term = '%'.addcslashes(trim($search), '%_\\').'%';

        return $query->where(function (Builder $q) use ($term, $columns) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'LIKE', $term);
            }
        });
    }
}
