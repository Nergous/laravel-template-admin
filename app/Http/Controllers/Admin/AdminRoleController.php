<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Pagination\PerPage;
use App\Http\Requests\RoleRequest;
use App\Http\Sorts\RoleSort;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleService;
use App\Support\RbacGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
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
 * Resource pages provide stable URLs for viewing, creating, and editing roles.
 */
class AdminRoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    /**
     * List of roles with permission and user counts, search, sorting, and page size.
     */
    public function index(Request $request, RoleSort $sort, PerPage $perPage): Response|RedirectResponse
    {
        // Permissions are loaded for RbacGuard::canManageRole() (can_edit).
        $query = Role::query()
            ->with('permissions:id,name')
            ->withCount(['permissions', 'users']);

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        $roles = $query
            ->orderBy($sort->getSort(), $sort->getDirection())
            ->orderBy('id')
            ->paginate($perPage->get())
            ->withQueryString();

        if ($redirect = $this->redirectPastLastPage($roles, $request)) {
            return $redirect;
        }

        // Send only the data used by the overview; the detail/form pages load more.
        $roles->setCollection($roles->getCollection()->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'is_system' => (bool) $role->is_system,
            'can_edit' => $request->user()?->can('roles.edit') && RbacGuard::canManageRole($request->user(), $role),
            'permissions_count' => $role->permissions_count,
            'users_count' => $role->users_count,
            'created_at' => optional($role->created_at)->toIso8601String(),
        ]));

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'permissionsTotal' => Permission::count(),
            'filters' => $request->only('search'),
            ...$sort->toArray(),
            ...$perPage->toArray(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Roles/FormPage', [
            'mode' => 'create',
            'allPermissions' => $this->groupedPermissions(),
        ]);
    }

    /** Creates a role, assigns permissions, and logs the action. */
    public function store(RoleRequest $request): RedirectResponse
    {
        $role = $this->roles->create(
            ['name' => $request->name, 'description' => $request->input('description')],
            $request->input('permissions', []),
            $request->user(),
        );

        return redirect()
            ->route('admin.roles.show', $role)
            ->with('success', 'Роль создана');
    }

    public function edit(Request $request, Role $role): Response
    {
        // roles.edit is checked by the route; the role must not be above the actor.
        abort_unless(RbacGuard::canManageRole($request->user(), $role), 403);

        return Inertia::render('Roles/FormPage', [
            'mode' => 'edit',
            'role' => $this->roleDetails($role),
            'allPermissions' => $this->groupedPermissions(),
        ]);
    }

    /**
     * Updates a role and its permissions (a system role's name cannot be changed — rule
     * in the service), and logs the action. Permissions are synced only when
     * permissions[] is sent; a client that omits the key keeps them.
     */
    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $this->roles->update(
            $role,
            ['name' => $request->name, 'description' => $request->input('description')],
            $request->exists('permissions') ? ($request->input('permissions') ?? []) : null,
            $request->user(),
        );

        return redirect()
            ->route('admin.roles.show', $role)
            ->with('success', 'Роль обновлена');
    }

    /** Deletes a role (system roles and roles assigned to users are not allowed — rules in the service), and logs the action. */
    public function destroy(Role $role): RedirectResponse
    {
        $this->roles->delete($role);

        return $this->redirectToList('admin.roles.index')
            ->with('success', 'Роль удалена');
    }

    public function show(Role $role): Response
    {
        return Inertia::render('Roles/Show', [
            'role' => $this->roleDetails($role),
        ]);
    }

    private function roleDetails(Role $role): array
    {
        $role->load('permissions:id,name')->loadCount('users');
        $authors = User::whereIn('id', array_filter([$role->created_by, $role->updated_by]))
            ->pluck('name', 'id');

        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'is_system' => (bool) $role->is_system,
            'can_edit' => request()->user()?->can('roles.edit') && RbacGuard::canManageRole(request()->user(), $role),
            'users_count' => $role->users_count,
            'permission_names' => $role->permissions->pluck('name')->values(),
            'creator_name' => $role->created_by ? ($authors[$role->created_by] ?? null) : null,
            'editor_name' => $role->updated_by ? ($authors[$role->updated_by] ?? null) : null,
            'created_at' => optional($role->created_at)->toIso8601String(),
            'updated_at' => optional($role->updated_at)->toIso8601String(),
        ];
    }

    /**
     * Groups all permissions by the prefix up to the first dot.
     * `users.view`, `users.edit` → ['users' => [...]]
     *
     * @return array<string, Collection<int, Permission>>
     */
    protected function groupedPermissions(): array
    {
        return Permission::orderBy('name')
            ->get()
            ->groupBy(fn (Permission $p) => str_contains($p->name, '.') ? strstr($p->name, '.', true) : 'other')
            ->all();
    }
}
