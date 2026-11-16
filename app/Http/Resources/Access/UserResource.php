<?php

namespace App\Http\Resources\Access;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of the user list and the user trash (AdminUser in
 * resources/js/admin/types.ts). can_manage is the UserPolicy rule for the
 * current user; the lists preload roles.permissions and permissions to avoid a
 * query per row.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles
                ->map(fn (Role $role) => $role->only(['id', 'name', 'description']))
                ->values()),
            'can_manage' => $request->user()?->can('update', $this->resource) === true,
        ];
    }
}
