<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * Общие проверки анти-эскалации привилегий для RBAC.
 *
 * Принципы:
 *  - полный админ обходит проверки (держит все права явно — см. RolePermissionSeeder);
 *  - «нельзя выдать больше, чем имеешь сам»: право выдаётся, только если оно есть
 *    у актора;
 *  - системные роли (is_system: admin/operator) меняет/назначает только админ.
 */
class RbacGuard
{
    /**
     * Имя роли суперадмина — единый источник правды,
     * берётся из config('rbac.superadmin_role'). Это роль с полным доступом:
     * её права заблокированы в матрице и её нельзя снять у себя. Отличается от
     * «защищённой» роли (is_system: нельзя удалить/переименовать), которой могут
     * быть несколько ролей.
     */
    public static function superadminRole(): string
    {
        return config('rbac.superadmin_role', 'admin');
    }

    /** Является ли роль суперадмином (по имени из config('rbac.superadmin_role')). */
    public static function isSuperadminRole(Role $role): bool
    {
        return $role->name === self::superadminRole();
    }

    /** Полный администратор обходит проверки анти-эскалации. */
    public static function isAdmin(?User $actor): bool
    {
        return $actor?->hasRole(self::superadminRole()) === true;
    }

    /**
     * Может ли актор выдать данное право: только если владеет им сам
     * (или он админ). Защищает от выдачи прав выше собственного уровня.
     */
    public static function canGrantPermission(?User $actor, ?string $permission): bool
    {
        if ($actor === null || $permission === null) {
            return false;
        }

        return self::isAdmin($actor) || $actor->can($permission);
    }

    /**
     * Может ли актор назначать/изменять данную роль: системные роли —
     * только админу, кастомные — всем (с учётом остальных проверок).
     */
    public static function canManageRole(?User $actor, Role $role): bool
    {
        if ($actor === null) {
            return false;
        }

        return self::isAdmin($actor) || ! $role->is_system;
    }
}
