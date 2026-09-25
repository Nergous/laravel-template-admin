<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The notification bell: muted categories, collapsed failed-login bursts and
 * the preferences endpoint.
 */
class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    private function logAs(?User $actor, ?Model $subject, string $action, ?string $label = null): void
    {
        ActivityLog::actingAs($actor, fn () => ActivityLog::record($subject, $action, null, $label));
    }

    public function test_muted_categories_are_left_out_of_the_bell(): void
    {
        $me = $this->actingAsUserWith(['activity-log.view']);
        $other = User::factory()->create();
        $media = Media::withoutEvents(fn () => Media::create(['filename' => 'media/a.txt', 'original_name' => 'a.txt']));
        ActivityLog::query()->delete(); // account setup entries are not part of the scenario

        $this->logAs($other, $media, 'updated');
        $this->logAs($other, null, 'settings_updated');
        $this->logAs(null, null, 'backup_created', 'db.sqlite');

        $this->getJson(route('admin.notifications.recent'))
            ->assertOk()
            ->assertJsonCount(3, 'items')
            ->assertJsonPath('count', 3)
            ->assertJsonPath('mutes', []);

        $this->putJson(route('admin.notifications.preferences'), ['mutes' => ['media', 'system']])
            ->assertOk()
            ->assertJsonPath('mutes', ['media', 'system'])
            ->assertJsonPath('count', 1);

        $this->assertSame(['media', 'system'], $me->fresh()->notification_mutes);
        $this->getJson(route('admin.notifications.recent'))
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.category', 'settings');
        $this->getJson(route('admin.notifications.count'))->assertJsonPath('count', 1);

        // Preferences are personal: no audit entry for them.
        $this->assertFalse(ActivityLog::where('subject_type', User::class)->where('subject_id', $me->id)->exists());
    }

    public function test_preferences_reject_unknown_categories(): void
    {
        $this->actingAsUserWith(['activity-log.view']);

        $this->putJson(route('admin.notifications.preferences'), ['mutes' => ['nope']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mutes.0');
        $this->putJson(route('admin.notifications.preferences'), ['mutes' => []])
            ->assertOk()
            ->assertJsonPath('mutes', []);
    }

    public function test_a_burst_of_failed_logins_counts_once(): void
    {
        $this->actingAsUserWith(['activity-log.view']);
        ActivityLog::query()->delete();

        foreach (range(1, 5) as $i) {
            $this->logAs(null, null, 'login_failed', 'victim@example.test');
        }
        $this->logAs(null, null, 'login_failed', 'other@example.test');

        $this->getJson(route('admin.notifications.recent'))
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.subject', 'other@example.test')
            ->assertJsonPath('items.0.repeat', 1)
            ->assertJsonPath('items.1.subject', 'victim@example.test')
            ->assertJsonPath('items.1.repeat', 5)
            ->assertJsonPath('items.1.category', 'auth');
    }
}
