<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use App\Services\System\ActivitySubjectLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

/** Log entries link only to pages the viewer may open and only for subjects that still exist. */
class ActivitySubjectLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Role $role;

    /** @var Collection<int, ActivityLog> */
    private Collection $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $gone = User::factory()->create();
        $this->role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $media = Media::factory()->create();

        $this->logs = collect([
            $this->entry(User::class, $this->user->id, $this->user->name),
            $this->entry(User::class, $gone->id, $gone->name),
            $this->entry(SpatieRole::class, $this->role->id, 'editor'),
            $this->entry(Media::class, $media->id, 'photo.jpg'),
        ]);

        $gone->forceDelete();
    }

    private function entry(string $type, ?int $id, ?string $label): ActivityLog
    {
        return ActivityLog::create([
            'action' => 'updated',
            'subject_type' => $type,
            'subject_id' => $id,
            'subject_label' => $label,
            'created_at' => now(),
        ]);
    }

    /** @return array<int, string> */
    private function linksFor(array $permissions): array
    {
        return app(ActivitySubjectLinks::class)->for($this->logs, $this->actingAsUserWith($permissions));
    }

    public function test_no_links_without_the_section_permissions(): void
    {
        $this->assertSame([], $this->linksFor(['activity-log.view']));
    }

    public function test_view_permissions_link_to_pages_and_lists(): void
    {
        [$user, $gone, $role, $media] = $this->logs->all();

        $links = $this->linksFor(['activity-log.view', 'users.view', 'roles.view', 'media.view']);

        $this->assertSame(route('admin.users.show', $this->user->id), $links[$user->id]);
        $this->assertArrayNotHasKey($gone->id, $links); // the user is gone
        $this->assertSame(route('admin.roles.show', $this->role->id), $links[$role->id]);
        $this->assertSame(route('admin.media.index', ['search' => 'photo.jpg']), $links[$media->id]);
    }

    public function test_deleted_role_gets_no_link(): void
    {
        $role = $this->logs[2];
        $this->role->delete();

        $this->assertArrayNotHasKey($role->id, $this->linksFor(['roles.view']));
    }
}
