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
 * Role management: list, CRUD, and a role's permission set.
 *
 * The controller handles HTTP: validates input (RoleRequest), assembles Inertia
 * props (including grouped permissions and authorship metadata), and redirects.
 * Orchestration and invariants (transactions, syncPermissions, logging the delta,
 * protecting system roles) live in App\Services\RoleService.
 *
 * System roles (is_system) are protected: their name cannot be changed and deletion
 * is forbidden. Actions on roles are written to the activity log (ActivityLog).
 * Creation and editing live in a drawer on the Index page, so the resource methods
 * create/edit/show merely redirect, while index returns everything the drawer needs
 * (permissions + metadata).
 */
class AdminRoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    /**
     * List of roles with permission and user counts, with search by name.
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

        // Author names for all roles on the page — in a single query (for the drawer).
        $authorNames = User::whereIn(
            'id',
            collect($roles->items())
                ->flatMap(fn (Role $role) => [$role->created_by, $role->updated_by])
                ->filter()->unique()
        )->pluck('name', 'id');

        // Enrich each row with what the create/edit drawer needs:
        // the permission list (names) and authorship metadata.
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
        // Creation lives in a drawer on the Index page.
        return redirect()->route('admin.roles.index');
    }

    /** Creates a role, assigns permissions, and logs the action. */
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
        // Editing lives in a drawer on the Index page.
        return redirect()->route('admin.roles.index');
    }

    /** Updates a role and its permissions (a system role's name cannot be changed — rule in the service), and logs the action. */
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

    /** Deletes a role (system roles and roles assigned to users are not allowed — rules in the service), and logs the action. */
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
     * Groups all permissions by the prefix up to the first dot.
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
