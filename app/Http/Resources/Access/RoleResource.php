<?php

namespace App\Http\Resources\Access;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A role list row (AdminRole in resources/js/admin/types.ts).
 * can_edit / can_delete are roles.edit / roles.delete plus the RolePolicy rule
 * for the current user; the list preloads permissions for the policy.
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => $this->is_system,
            'can_edit' => $user?->can('roles.edit') === true && $user->can('update', $this->resource),
            'can_delete' => $user?->can('roles.delete') === true && $user->can('delete', $this->resource),
            'permissions_count' => $this->whenCounted('permissions'),
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
