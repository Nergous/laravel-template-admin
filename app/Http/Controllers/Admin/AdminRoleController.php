<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;

/**
 * Управление ролями: список, CRUD и набор прав роли.
 *
 * Контроллер занимается HTTP: валидирует вход (RoleRequest), собирает пропсы
 * Inertia (включая сгруппированные права и метаданные авторства) и редиректит.
 * Оркестрация и инварианты (транзакции, syncPermissions, журналирование дельты,
 * защита системных ролей) живут в App\Services\RoleService.
 *
 * Системные роли (is_system) защищены: имя менять нельзя, удаление запрещено.
 * Действия над ролями пишутся в журнал (ActivityLog). show редиректит на edit.
 */
class AdminRoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    /**
     * Список ролей со счётчиками прав и пользователей, с поиском по имени.
     */
    public function index(Request $request): \Inertia\Response
    {
        $query = Role::query()->withCount(['permissions', 'users']);

        if ($request->filled('search')) {
            $query->where('name', 'LIKE', '%'.$request->search.'%');
        }

        $roles = $query->orderBy('name')->paginate(15)->withQueryString();

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'permissionsTotal' => Permission::count(),
            'filters' => $request->only('search'),
        ]);
    }

    /**
     * Форма создания роли со сгруппированными по ресурсам правами.
     */
    public function create(): \Inertia\Response
    {
        return Inertia::render('Roles/Create', [
            'allPermissions' => $this->groupedPermissions(),
            'assigned' => [],
        ]);
    }

    /** Создаёт роль, назначает права и логирует; поддерживает «создать и остаться». */
    public function store(RoleRequest $request): RedirectResponse
    {
        $this->roles->create(
            ['name' => $request->name, 'description' => $request->input('description')],
            $request->input('permissions', []),
            $request->user(),
        );

        if ($request->boolean('stay')) {
            return redirect()
                ->route('admin.roles.create')
                ->with('success', 'Роль создана. Можно добавить ещё одну.');
        }

        return redirect()
            ->route('admin.roles.index')
            ->with('success', 'Роль создана');
    }

    /**
     * Форма редактирования роли: текущие права и метаданные авторства.
     */
    public function edit(Role $role): \Inertia\Response
    {
        return Inertia::render('Roles/Edit', [
            'role' => $role,
            'allPermissions' => $this->groupedPermissions(),
            'assigned' => $role->permissions->pluck('name')->toArray(),
            'meta' => $this->roleMeta($role),
        ]);
    }

    /** Обновляет роль и её права (имя системной роли менять нельзя — правило в сервисе), логирует. */
    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $this->roles->update(
            $role,
            ['name' => $request->name, 'description' => $request->input('description')],
            $request->input('permissions', []),
            $request->user(),
        );

        return redirect()
            ->route('admin.roles.index')
            ->with('success', 'Роль обновлена');
    }

    /** Удаляет роль (нельзя системную и назначенную пользователям — правила в сервисе), логирует. */
    public function destroy(Role $role): RedirectResponse
    {
        $this->roles->delete($role);

        return redirect()
            ->route('admin.roles.index')
            ->with('success', 'Роль удалена');
    }

    public function show(Role $role): RedirectResponse
    {
        return redirect()->route('admin.roles.edit', $role);
    }

    /**
     * Метаданные авторства роли (имена создателя/редактора + даты) для формы.
     *
     * @return array<string, string|null>
     */
    private function roleMeta(Role $role): array
    {
        $names = User::whereIn('id', array_filter([$role->created_by, $role->updated_by]))
            ->pluck('name', 'id');

        return [
            'created_by' => $role->created_by ? ($names[$role->created_by] ?? null) : null,
            'updated_by' => $role->updated_by ? ($names[$role->updated_by] ?? null) : null,
            'created_at' => optional($role->created_at)->toIso8601String(),
            'updated_at' => optional($role->updated_at)->toIso8601String(),
        ];
    }

    /**
     * Группирует все permissions по префиксу до первой точки.
     * `users.view`, `users.edit` → ['users' => [...]]
     *
     * @return array<string, \Illuminate\Support\Collection<int, Permission>>
     */
    protected function groupedPermissions(): array
    {
        return Permission::orderBy('name')
            ->get()
            ->groupBy(fn (Permission $p) => str_contains($p->name, '.') ? strstr($p->name, '.', true) : 'other')
            ->all();
    }
}
