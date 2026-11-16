<?php

namespace App\Services\Access;

use App\Events\ActivityOccurred;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionDiff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Role operations: creating and changing a role together with its permission
 * set, and deleting it, with logging and the rules that hold for any caller: a
 * system role cannot be renamed or deleted, the superadmin role's permissions
 * never change, a role assigned to users cannot be deleted.
 *
 * Who may change which role (RolePolicy) and which permissions to grant
 * (RoleRequest, RbacGuard::canGrantPermission()) is checked before the call.
 */
class RoleService
{
    public function __construct(private readonly RbacGuard $rbac) {}

    /** @return Builder<Role> */
    public function listQuery(?string $search): Builder
    {
        return Role::query()->when(filled($search), fn (Builder $q) => $q->search((string) $search));
    }

    /**
     * Creates a role, grants its permissions and logs it (in a transaction).
     *
     * @param  array{name: string, description?: string|null}  $data
     * @param  array<int, string>  $permissions  Permission names.
     */
    public function create(array $data, array $permissions, User $actor): Role
    {
        return DB::transaction(function () use ($data, $permissions, $actor) {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => 'web',
                'description' => $data['description'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $role->syncPermissions($permissions);
            ActivityOccurred::by($actor, $role, 'created');

            return $role;
        });
    }

    /**
     * Changes the role and, when $permissions is given, its permission set, and
     * logs the changes. null in $permissions keeps the current set.
     *
     * @param  array{name: string, description?: string|null}  $data
     * @param  array<int, string>|null  $permissions
     *
     * @throws ValidationException If a system role is renamed or the superadmin role's permissions change.
     */
    public function update(Role $role, array $data, ?array $permissions, User $actor): Role
    {
        if ($role->is_system && $data['name'] !== $role->name) {
            throw ValidationException::withMessages([
                'name' => 'Имя системной роли нельзя менять',
            ]);
        }

        $before = [
            'name' => $role->name,
            'description' => $role->description,
            'permissions' => $role->permissions->pluck('name')->all(),
        ];

        if ($permissions !== null && $this->rbac->isSuperadminRole($role) && PermissionDiff::differs($before['permissions'], $permissions)) {
            throw ValidationException::withMessages([
                'permissions' => 'Права роли «'.$role->name.'» нельзя изменять',
            ]);
        }

        return DB::transaction(function () use ($role, $data, $permissions, $actor, $before) {
            $role->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'updated_by' => $actor->id,
            ]);

            if ($permissions !== null) {
                $role->syncPermissions($permissions);
            }

            ActivityOccurred::by($actor, $role, 'updated', $this->changesSince($role, $before) ?: null);

            return $role;
        });
    }

    /**
     * Deletes the role and logs it. A system role and a role assigned to users
     * cannot be deleted.
     *
     * @throws ValidationException If the role is a system one or assigned to users.
     */
    public function delete(Role $role, User $actor): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => 'Системную роль нельзя удалить',
            ]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'role' => 'Нельзя удалить роль, назначенную пользователям',
            ]);
        }

        // Only a deletion that actually happened is logged.
        DB::transaction(function () use ($role, $actor) {
            $role->delete();
            ActivityOccurred::by($actor, $role, 'deleted');
        });
    }

    /**
     * Changes for the log: name and description as [before, after] and the
     * granted or revoked permissions (PermissionDiff).
     *
     * @param  array{name: string, description: string|null, permissions: list<string>}  $before
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function changesSince(Role $role, array $before): array
    {
        $changes = [];

        foreach (['name', 'description'] as $field) {
            if ($before[$field] !== $role->{$field}) {
                $changes[$field] = [$before[$field], $role->{$field}];
            }
        }

        return [
            ...$changes,
            ...PermissionDiff::changes($before['permissions'], $role->permissions()->pluck('name')->all()),
        ];
    }
}
