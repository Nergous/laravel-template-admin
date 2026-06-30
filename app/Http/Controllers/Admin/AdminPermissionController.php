<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PermissionRequest;
use App\Models\Role;
use App\Services\PermissionService;
use App\Support\RbacGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;

/**
 * Управление разрешениями: матрица «роль × разрешение» и CRUD над правами.
 *
 * Контроллер занимается HTTP: валидирует вход, собирает пропсы матрицы и
 * редиректит. Оркестрация и инварианты (анти-эскалация, защита роли суперадмина,
 * журналирование, авто-грант при создании) живут в App\Services\PermissionService.
 *
 * Права роли суперадмина (config('rbac.superadmin_role')) защищены от изменения.
 * Отдельных страниц create/edit/show нет — всё редактируется в матрице, поэтому эти
 * resource-методы лишь редиректят на index.
 */
class AdminPermissionController extends Controller
{
    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * Матрица «роль × разрешение».
     *
     * Отдаёт: roles (колонки), groups (строки, сгруппированные по ресурсу),
     * matrix (карта role_id → [имена разрешений]).
     */
    public function index(Request $request): \Inertia\Response
    {
        $permissions = Permission::orderBy('name')->get();

        $roles = Role::with('permissions:id,name')->orderBy('name')->get();

        $groups = $permissions
            ->groupBy(fn (Permission $p) => Str::contains($p->name, '.') ? Str::before($p->name, '.') : 'other')
            ->map(fn ($items, $resource) => [
                'resource' => $resource,
                'label' => $this->resourceLabel($resource),
                'permissions' => $items->map(function (Permission $p) {
                    $action = Str::contains($p->name, '.') ? Str::after($p->name, '.') : $p->name;

                    return [
                        'id' => $p->id,
                        'name' => $p->name,
                        'action' => $action,
                        'label' => $this->actionLabel($action),
                    ];
                })->values(),
            ])
            ->values();

        return Inertia::render('Permissions/Index', [
            'roles' => $roles->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'label' => $r->name,
                'is_system' => (bool) $r->is_system,
                'locked' => RbacGuard::isSuperadminRole($r),
            ])->values(),
            'groups' => $groups,
            'matrix' => $roles->mapWithKeys(fn (Role $r) => [
                $r->id => $r->permissions->pluck('name')->all(),
            ]),
            'filters' => $request->only('search'),
        ]);
    }

    /**
     * Переключение одной ячейки матрицы: выдать/снять разрешение у роли.
     * Анти-эскалация и журналирование — в сервисе.
     */
    public function sync(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'permission' => ['required', 'string', 'exists:permissions,name'],
            'granted' => ['required', 'boolean'],
        ]);

        $this->permissions->toggle(
            $data['role_id'],
            $data['permission'],
            $data['granted'],
            $request->user(),
        );

        return back()->with('success', 'Матрица доступа обновлена');
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('admin.permissions.index');
    }

    /** Создаёт новое разрешение (guard web), авто-выдаёт его роли admin и логирует. */
    public function store(PermissionRequest $request): RedirectResponse
    {
        $this->permissions->create($request->name);

        return redirect()
            ->route('admin.permissions.index')
            ->with('success', 'Разрешение создано');
    }

    public function edit(Permission $permission): RedirectResponse
    {
        return redirect()->route('admin.permissions.index');
    }

    /** Переименовывает разрешение и логирует изменение имени. */
    public function update(PermissionRequest $request, Permission $permission): RedirectResponse
    {
        $this->permissions->update($permission, $request->name);

        return redirect()
            ->route('admin.permissions.index')
            ->with('success', 'Разрешение обновлено');
    }

    /** Удаляет разрешение с записью в журнал. */
    public function destroy(Permission $permission): RedirectResponse
    {
        $this->permissions->delete($permission);

        return redirect()
            ->route('admin.permissions.index')
            ->with('success', 'Разрешение удалено');
    }

    public function show(Permission $permission): RedirectResponse
    {
        return redirect()->route('admin.permissions.index');
    }

    /**
     * Локализованное название ресурса (префикс до точки); строки — в
     * lang/<locale>/permissions.php. Фолбэк — сам префикс с заглавной.
     */
    private function resourceLabel(string $resource): string
    {
        $key = "permissions.resources.$resource";

        return Lang::has($key) ? __($key) : Str::ucfirst($resource);
    }

    /**
     * Локализованное название действия (суффикс после точки); строки — в
     * lang/<locale>/permissions.php. Фолбэк — само действие с заглавной.
     */
    private function actionLabel(string $action): string
    {
        $key = "permissions.actions.$action";

        return Lang::has($key) ? __($key) : Str::ucfirst($action);
    }
}
