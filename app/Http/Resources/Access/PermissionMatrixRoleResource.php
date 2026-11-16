<?php

namespace App\Http\Resources\Access;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A role column of the access matrix (MatrixRole in Permissions/Index.vue).
 * locked marks the superadmin role, whose permissions never change; the caller
 * passes it (RbacGuard::isSuperadminRole()). manageable — whether the current
 * user may toggle the role's cells (RolePolicy). Expects permissions loaded
 * for the policy.
 *
 * @mixin Role
 */
class PermissionMatrixRoleResource extends JsonResource
{
    public function __construct(Role $role, private readonly bool $locked)
    {
        parent::__construct($role);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_system' => $this->is_system,
            'locked' => $this->locked,
            'manageable' => ! $this->locked && $request->user()?->can('update', $this->resource) === true,
        ];
    }
}
