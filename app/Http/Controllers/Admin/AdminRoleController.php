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
 * Действия над ролями пишутся в журнал (ActivityLog). Создание и редактирование
 * живут в дровере на странице Index, поэтому resource-методы create/edit/show
 * лишь редиректят, а index отдаёт всё нужное для дровера (права + метаданные).
 */
class AdminRoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    /**
     * Список ролей со счётчиками прав и пользователей, с поиском по имени.
     */
    public function index(Request $request): \Inertia\Response
    {
        $query = Role::query()
            ->with('permissions:id,name')
            ->withCount(['permissions', 'users']);

        if ($request->filled('search')) {
            $query->where('name', 'LIKE', '%'.$request->search.'%');
        }

        $roles = $query->orderBy('name')->paginate(15)->withQueryString();

        // Имена авторов для всех ролей страницы — одним запросом (для дровера).
        $authorNames = User::whereIn(
            'id',
            collect($roles->items())
                ->flatMap(fn (Role $role) => [$role->created_by, $role->updated_by])
                ->filter()->unique()
        )->pluck('name', 'id');

        // Каждую строку обогащаем тем, что нужно дроверу создания/редактирования:
        // список прав (имена) и метаданные авторства.
        $roles->setCollection($roles->getCollection()->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'is_system' => (bool) $role->is_system,
            'permissions_count' => $role->permissions_count,
            'users_count' => $role->users_count,
            'permission_names' => $role->permissions->pluck('name')->values(),
            'creator_name' => $role->created_by ? ($authorNames[$role->created_by] ?? null) : null,
            'editor_name' => $role->updated_by ? ($authorNames[$role->updated_by] ?? null) : null,
            'created_at' => optional($role->created_at)->toIso8601String(),
            'updated_at' => optional($role->updated_at)->toIso8601String(),
        ]));

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'permissionsTotal' => Permission::count(),
            'allPermissions' => $this->groupedPermissions(),
            'filters' => $request->only('search'),
        ]);
    }

    public function create(): RedirectResponse
    {
        // Создание живёт в дровере на странице Index.
        return redirect()->route('admin.roles.index');
    }

    /** Создаёт роль, назначает права и логирует. */
    public function store(RoleRequest $request): RedirectResponse
    {
        $this->roles->create(
            ['name' => $request->name, 'description' => $request->input('description')],
            $request->input('permissions', []),
            $request->user(),
        );

        return redirect()
            ->route('admin.roles.index')
            ->with('success', 'Роль создана');
    }

    public function edit(Role $role): RedirectResponse
    {
        // Редактирование живёт в дровере на странице Index.
        return redirect()->route('admin.roles.index');
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
