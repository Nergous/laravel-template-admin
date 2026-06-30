<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Роль приложения поверх spatie-модели.
 *
 * Базовая модель spatie использует `$guarded = []` (всё массово присваиваемо).
 * Здесь мы защищаем `is_system`: этот флаг помечает неудаляемые/непереименовываемые
 * системные роли (admin/operator) и НЕ должен приходить из пользовательского ввода —
 * иначе неосторожный `Role::create($request->all())` обошёл бы защиту системной роли.
 * Доверенный код (сидер) выставляет флаг явным присваиванием: `$role->is_system = true`.
 *
 * `created_by`/`updated_by` остаются fillable — их выставляет RoleService из id
 * актора (серверная сторона), а не пользовательский ввод.
 *
 * Модель подключается как каноническая через config/permission.php → models.role.
 */
class Role extends SpatieRole
{
    /** @var list<string> */
    protected $guarded = ['id', 'is_system'];

    /**
     * Полиморфный тип субъекта оставляем равным базовому spatie-классу.
     *
     * Журнал действий (ActivityLog) пишет subject_type через getMorphClass(); тип
     * сознательно держат стабильным (см. шапку App\Models\ActivityLog и
     * config/audit.php → subjects). Подмена модели на этот подкласс ради защиты
     * is_system не должна менять уже накопленный subject_type ролей.
     */
    public function getMorphClass(): string
    {
        return SpatieRole::class;
    }
}
