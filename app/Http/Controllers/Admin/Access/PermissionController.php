<?php

namespace App\Http\Controllers\Admin\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\BulkTogglePermissionsRequest;
use App\Http\Requests\Access\TogglePermissionRequest;
use App\Http\Resources\Access\PermissionMatrixRoleResource;
use App\Models\Role;
use App\Services\Access\PermissionCatalog;
use App\Services\Access\PermissionService;
use App\Services\Access\RbacGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Permission management: the "role × permission" matrix. Permissions themselves
 * are defined in code (seeders and migrations): a permission that is never
 * checked has no effect, and renaming one that code checks would break access.
 *
 * The controller handles HTTP: it validates input, builds the matrix props and
 * redirects. Orchestration and invariants (privilege-escalation protection,
 * superadmin role protection, logging) live in App\Services\Access\PermissionService.
 *
 * The superadmin role's permissions (config('rbac.superadmin_role')) cannot be changed.
 */
class PermissionController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly RbacGuard $rbac,
    ) {}

    /**
     * The "role × permission" matrix.
     *
     * Returns: roles (columns, PermissionMatrixRoleResource), groups (rows grouped
     * by resource), matrix (a role_id → [permission names] map).
     */
    public function index(Request $request, PermissionCatalog $catalog): Response
    {
        $roles = Role::with('permissions:id,name')->orderBy('name')->get();

        return Inertia::render('Permissions/Index', [
            'roles' => $roles->map(fn (Role $role) => (new PermissionMatrixRoleResource(
                $role,
                locked: $this->rbac->isSuperadminRole($role),
            ))->resolve($request))->values(),
            'superadminRole' => $this->rbac->superadminRole(),
            'groups' => $catalog->matrixGroups(),
            'matrix' => $roles->mapWithKeys(fn (Role $role) => [
                $role->id => $role->permissions->pluck('name')->all(),
            ]),
            'filters' => $request->only('search'),
        ]);
    }

    /**
     * Toggles one matrix cell: grant or revoke a permission for a role.
     * Privilege-escalation protection and logging live in the service.
     */
    public function toggle(TogglePermissionRequest $request): RedirectResponse
    {
        $this->permissions->toggle(
            $request->role(),
            $request->validated('permission'),
            $request->boolean('granted'),
            $request->user(),
        );

        return back()->with('success', 'Матрица доступа обновлена');
    }

    /**
     * Bulk toggle: a whole row (one permission for several roles) or a group
     * column (several permissions for one role). The rules live in the service.
     */
    public function toggleMany(BulkTogglePermissionsRequest $request): RedirectResponse
    {
        $changed = $this->permissions->toggleMany(
            $request->validated('role_ids'),
            $request->validated('permissions'),
            $request->boolean('granted'),
            $request->user(),
        );

        return back()->with('success', "Матрица доступа обновлена. Изменено ролей: {$changed}");
    }
}
