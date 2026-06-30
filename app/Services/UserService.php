<?php

namespace App\Services;

use App\Models\User;
use App\Support\RbacGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Доменные операции над пользователями: создание/обновление с ролями, мягкое
 * удаление и операции корзины.
 */
class UserService
{
    /**
     * Создаёт пользователя и назначает ему роли (в транзакции).
     *
     * @param  array{name: string, email: string, password: string}  $data
     * @param  array<int, string>  $roles  Имена ролей spatie.
     */
    public function create(array $data, array $roles): User
    {
        return DB::transaction(function () use ($data, $roles) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $user->syncRoles($roles);

            return $user;
        });
    }

    /**
     * Обновляет пользователя и его роли. Пароль меняется только если передан;
     * админ не может снять роль admin с самого себя.
     *
     * @param  array{name: string, email: string, password?: string|null}  $data
     * @param  array<int, string>  $roles
     *
     * @throws ValidationException Если актор снимает у себя роль admin.
     */
    public function update(User $user, array $data, array $roles, ?User $actor): User
    {
        if ($this->isRemovingOwnAdmin($user, $roles, $actor)) {
            throw ValidationException::withMessages([
                'roles' => 'Вы не можете убрать у себя роль admin',
            ]);
        }

        return DB::transaction(function () use ($user, $data, $roles) {
            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => filled($data['password'] ?? null)
                    ? Hash::make($data['password'])
                    : $user->password,
            ]);

            $user->syncRoles($roles);

            return $user;
        });
    }

    /**
     * Мягко удаляет пользователя (себя удалить нельзя).
     *
     * @throws ValidationException Если актор пытается удалить самого себя.
     */
    public function delete(User $user, ?User $actor): void
    {
        if ($actor !== null && $actor->id === $user->id) {
            throw ValidationException::withMessages([
                'user' => 'Вы не можете удалить самого себя',
            ]);
        }

        $user->delete();
    }

    /**
     * Восстанавливает пользователя из корзины.
     *
     * @param  int  $id  Идентификатор пользователя в корзине
     */
    public function restore(int $id): void
    {
        User::onlyTrashed()->findOrFail($id)->restore();
    }

    /**
     * Удаляет пользователя из корзины окончательно (вместе с файлами/связями).
     *
     * @param  int  $id  Идентификатор пользователя в корзине
     */
    public function forceDelete(int $id): void
    {
        User::onlyTrashed()->findOrFail($id)->forceDelete();
    }

    /**
     * Массовое восстановление из корзины.
     *
     * @param  array<int, int>  $ids
     * @return int Сколько пользователей обработано.
     */
    public function bulkRestore(array $ids): int
    {
        return $this->applyToTrashed($ids, fn (User $user) => $user->restore());
    }

    /**
     * Массовое окончательное удаление из корзины.
     *
     * @param  array<int, int>  $ids
     * @return int Сколько пользователей обработано.
     */
    public function bulkForceDelete(array $ids): int
    {
        return $this->applyToTrashed($ids, fn (User $user) => $user->forceDelete());
    }

    /**
     * Применяет действие к каждому пользователю из корзины с указанными id.
     * Идёт по моделям (не mass-update), чтобы restore/forceDelete бросали
     * события и попадали в журнал (LogsActivity).
     *
     * @param  array<int, int>  $ids
     * @param  callable(User): void  $action
     * @return int Сколько пользователей обработано.
     */
    private function applyToTrashed(array $ids, callable $action): int
    {
        $users = User::onlyTrashed()->whereIn('id', $ids)->get();

        $users->each($action);

        return $users->count();
    }

    /**
     * Защита от того, чтобы суперадмин снял с себя роль суперадмина
     * (config('rbac.superadmin_role')).
     *
     * @param  array<int, string>  $newRoles
     */
    private function isRemovingOwnAdmin(User $user, array $newRoles, ?User $actor): bool
    {
        $superadmin = RbacGuard::superadminRole();

        return $actor !== null
            && $actor->id === $user->id
            && $user->hasRole($superadmin)
            && ! in_array($superadmin, $newRoles, true);
    }
}
