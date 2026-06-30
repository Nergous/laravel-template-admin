<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Application role on top of the spatie model.
 *
 * The base spatie model uses `$guarded = []` (everything is mass assignable).
 * Here we guard `is_system`: this flag marks non-deletable/non-renamable system
 * roles (admin/operator) and must NOT come from user input — otherwise a careless
 * `Role::create($request->all())` would bypass the system-role protection.
 * Trusted code (the seeder) sets the flag via explicit assignment: `$role->is_system = true`.
 *
 * `created_by`/`updated_by` stay fillable — they are set by RoleService from the
 * actor's id (server side), not from user input.
 *
 * The model is wired in as canonical via config/permission.php → models.role.
 */
class Role extends SpatieRole
{
    /** @var list<string> */
    protected $guarded = ['id', 'is_system'];

    /**
     * Keep the polymorphic subject type equal to the base spatie class.
     *
     * The activity log (ActivityLog) writes subject_type via getMorphClass(); the
     * type is deliberately kept stable (see the App\Models\ActivityLog header and
     * config/audit.php → subjects). Swapping the model for this subclass to guard
     * is_system must not change the already accumulated subject_type of roles.
     */
    public function getMorphClass(): string
    {
        return SpatieRole::class;
    }
}
