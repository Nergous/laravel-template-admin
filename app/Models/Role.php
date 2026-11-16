<?php

namespace App\Models;

use App\Models\Concerns\HasSearch;
use App\Models\Concerns\TracksAuthor;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Application role on top of the spatie model.
 *
 * The base spatie model has `$guarded = []` (everything is mass-assignable).
 * Here `is_system` is guarded: the flag marks system roles (admin/operator)
 * that cannot be deleted or renamed, and it must NOT come from user input —
 * otherwise a careless `Role::create($request->all())` would bypass the system
 * role protection. Trusted code (the seeder) sets the flag by explicit
 * assignment: `$role->is_system = true`.
 *
 * `created_by`/`updated_by` stay fillable — RoleService sets them from the
 * actor id (on the server), not from user input. The creator() and editor()
 * relations come from TracksAuthor.
 *
 * The model is registered as the main one via config/permission.php → models.role.
 */
class Role extends SpatieRole
{
    use HasSearch, TracksAuthor;

    /** @var list<string> */
    protected $guarded = ['id', 'is_system'];

    /** @var list<string> Search by name and description (HasSearch). */
    protected array $searchable = ['name', 'description'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /**
     * The polymorphic subject type stays equal to the base spatie class.
     *
     * The activity log (ActivityLog) writes subject_type via getMorphClass(); the
     * type is deliberately stable (see the App\Models\ActivityLog header and
     * config/activity.php → subjects). Swapping the model for this subclass to
     * guard is_system must not change the subject_type already stored for roles.
     */
    public function getMorphClass(): string
    {
        return SpatieRole::class;
    }
}
