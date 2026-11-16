<?php

namespace App\Http\Controllers\Admin\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\RoleFilterRequest;
use App\Http\Requests\Access\RoleRequest;
use App\Http\Resources\Access\RoleDetailsResource;
use App\Http\Resources\Access\RoleResource;
use App\Models\Role;
use App\Services\Access\PermissionCatalog;
use App\Services\Access\RoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Role management: list, CRUD and the role's permission set.
 *
 * The controller handles HTTP: it validates input (RoleRequest), builds the
 * Inertia props (including grouped permissions and authorship data) and
 * redirects. Access is defined by the routes (roles.* permissions, RolePolicy
 * via "can:update,role" / "can:delete,role"). Orchestration and invariants
 * (transactions, syncPermissions, change logging, system role protection) live
 * in App\Services\Access\RoleService.
 *
 * System roles (is_system) are protected: they cannot be renamed or deleted.
 * Role actions are written to the activity log (ActivityLog). Resource pages
 * give stable URLs for viewing, creating and editing roles.
 */
class RoleController extends Controller
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly PermissionCatalog $permissions,
    ) {}

    /**
     * Role list with permission and user counts, search, sorting and page size.
     */
    public function index(RoleFilterRequest $request): Response|RedirectResponse
    {
        // Permissions are loaded for RolePolicy (can_edit).
        $roles = $this->roles->listQuery($request->search())
            ->with('permissions:id,name')
            ->withCount(['permissions', 'users'])
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        if ($redirect = $this->redirectPastLastPage($roles, $request)) {
            return $redirect;
        }

        // Return only what the overview needs; the show page and forms load more.
        $roles->through(fn (Role $role) => (new RoleResource($role))->resolve($request));

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'permissionsTotal' => $this->permissions->count(),
            'filters' => ['search' => $request->search()],
            ...$request->sortProps(),
            ...$request->perPageProps(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Roles/FormPage', [
            'mode' => 'create',
            'allPermissions' => $this->permissions->grouped(),
        ]);
    }

    /** Creates a role, assigns permissions and logs the action. */
    public function store(RoleRequest $request): RedirectResponse
    {
        $role = $this->roles->create($request->fields(), $request->permissionsToSync() ?? [], $request->user());

        return redirect()
            ->route('admin.roles.show', $role)
            ->with('success', 'Роль создана');
    }

    public function edit(Request $request, Role $role): Response
    {
        return Inertia::render('Roles/FormPage', [
            'mode' => 'edit',
            'role' => $this->roleDetails($request, $role),
            'allPermissions' => $this->permissions->grouped(),
        ]);
    }

    /**
     * Updates the role and its permissions (a system role cannot be renamed —
     * a service rule) and logs the action. Permissions are synced only when
     * permissions[] is passed (RoleRequest::permissionsToSync()).
     */
    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $this->roles->update($role, $request->fields(), $request->permissionsToSync(), $request->user());

        return redirect()
            ->route('admin.roles.show', $role)
            ->with('success', 'Роль обновлена');
    }

    /** Deletes a role (system roles and roles assigned to users cannot be deleted — service rules) and logs the action. */
    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->roles->delete($role, $request->user());

        return $this->redirectToList('admin.roles.index')
            ->with('success', 'Роль удалена');
    }

    public function show(Request $request, Role $role): Response
    {
        return Inertia::render('Roles/Show', [
            'role' => $this->roleDetails($request, $role),
        ]);
    }

    /** @return array<string, mixed> */
    private function roleDetails(Request $request, Role $role): array
    {
        $role->load(['permissions:id,name', 'creator:id,name', 'editor:id,name'])->loadCount('users');

        return (new RoleDetailsResource($role))->resolve($request);
    }
}
