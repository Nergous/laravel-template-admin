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
 * Permission management: the "role × permission" matrix and CRUD over permissions.
 *
 * The controller handles HTTP: validates input, assembles the matrix props, and
 * redirects. Orchestration and invariants (anti-escalation, protecting the superadmin
 * role, logging, auto-grant on creation) live in App\Services\PermissionService.
 *
 * The superadmin role's permissions (config('rbac.superadmin_role')) are protected
 * from changes. There are no separate create/edit/show pages — everything is edited
 * in the matrix, so these resource methods merely redirect to index.
 */
class AdminPermissionController extends Controller
{
    public function __construct(private readonly PermissionService $permissions) {}

    /**
     * The "role × permission" matrix.
     *
     * Returns: roles (columns), groups (rows, grouped by resource),
     * matrix (a map of role_id → [permission names]).
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
     * Toggling a single matrix cell: grant/revoke a permission for a role.
     * Anti-escalation and logging are in the service.
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

    /** Creates a new permission (guard web), auto-grants it to the admin role, and logs the action. */
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

    /** Renames a permission and logs the name change. */
    public function update(PermissionRequest $request, Permission $permission): RedirectResponse
    {
        $this->permissions->update($permission, $request->name);

        return redirect()
            ->route('admin.permissions.index')
            ->with('success', 'Разрешение обновлено');
    }

    /** Deletes a permission and writes an entry to the activity log. */
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
     * Localized resource label (the prefix before the dot); strings live in
     * lang/<locale>/permissions.php. Fallback is the prefix itself, capitalized.
     */
    private function resourceLabel(string $resource): string
    {
        $key = "permissions.resources.$resource";

        return Lang::has($key) ? __($key) : Str::ucfirst($resource);
    }

    /**
     * Localized action label (the suffix after the dot); strings live in
     * lang/<locale>/permissions.php. Fallback is the action itself, capitalized.
     */
    private function actionLabel(string $action): string
    {
        $key = "permissions.actions.$action";

        return Lang::has($key) ? __($key) : Str::ucfirst($action);
    }
}
