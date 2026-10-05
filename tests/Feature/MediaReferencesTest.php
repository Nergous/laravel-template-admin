<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Setting;
use App\Services\MediaReferenceUpdater;
use App\Services\MediaService;
use App\Support\MediaUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * References from the places registered in MediaReferenceRegistry. The
 * template registers the favicon and the OG image settings; project fields
 * (foreign keys, text columns) go through the same code paths.
 */
class MediaReferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_used_file_is_not_deleted(): void
    {
        Storage::fake('public');
        $this->actingAsUserWith(['media.view', 'media.delete']);
        $media = $this->image('used');
        Setting::set('seo', 'og_image', $media->url());

        $this->delete(route('admin.media.destroy', $media))->assertSessionHas('error');

        $this->assertDatabaseHas('media', ['id' => $media->id]);
        Storage::disk('public')->assertExists($media->filename);
    }

    public function test_bulk_delete_skips_used_files(): void
    {
        Storage::fake('public');
        $this->actingAsUserWith(['media.view', 'media.delete']);
        $used = $this->image('icon');
        $free = $this->image('free');
        Setting::set('general', 'favicon', $used->url());

        $this->delete(route('admin.media.bulk-destroy'), ['ids' => [$used->id, $free->id]])
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('media', ['id' => $used->id]);
        $this->assertDatabaseMissing('media', ['id' => $free->id]);
    }

    public function test_bulk_delete_reports_deleted_and_skipped(): void
    {
        Storage::fake('public');
        $used = $this->image('cover');
        $free = $this->image('spare');
        Setting::set('seo', 'og_image', $used->url());

        $result = app(MediaService::class)->bulkDelete([$used->id, $free->id]);

        $this->assertSame(1, $result['deleted']);
        $this->assertSame([$used->original_name], $result['skipped']);
    }

    public function test_a_thumbnail_link_counts_for_the_original(): void
    {
        Storage::fake('public');
        $media = $this->image('photo');
        Setting::set('seo', 'og_image', 'https://cdn.example.test/storage/media/photo.thumb.webp');

        $this->assertSame(['OG-изображение'], app(MediaUsage::class)->for([$media])[$media->id]);
    }

    public function test_details_list_places_with_edit_links(): void
    {
        Storage::fake('public');
        $this->actingAsUserWith(['media.view']);
        $media = $this->image('favicon');
        Setting::set('general', 'favicon', $media->url());

        $places = $this->getJson(route('admin.media.show', $media))->assertOk()->json('places');

        $this->assertSame([['label' => 'Фавикон', 'title' => null, 'url' => route('admin.settings.index')]], $places);
    }

    public function test_usage_filter_splits_used_and_unused_files(): void
    {
        Storage::fake('public');
        $this->actingAsUserWith(['media.view']);
        $used = $this->image('used');
        $this->image('unused');
        Setting::set('seo', 'og_image', $used->url());

        $this->get(route('admin.media.index', ['usage' => 'used']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('media.data', 1)
                ->where('media.data.0.id', $used->id)
            );
        $this->get(route('admin.media.index', ['usage' => 'unused']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('media.data', 1)
                ->where('media.data.0.original_name', 'unused.webp')
            );
    }

    public function test_links_follow_a_changed_file(): void
    {
        Storage::fake('public');
        $old = $this->image('before');
        Setting::set('seo', 'og_image', $old->url());
        Setting::set('general', 'favicon', '/storage/media/before-other.webp');

        $new = (clone $old)->forceFill(['filename' => 'media/after.webp']);
        $changed = app(MediaReferenceUpdater::class)->rewrite($old, $new);

        $this->assertSame(1, $changed);
        $this->assertSame($new->url(), Setting::value('seo', 'og_image'));
        $this->assertSame('/storage/media/before-other.webp', Setting::value('general', 'favicon'), 'a longer name is left alone');
    }

    public function test_usage_filter_finds_images_without_alt(): void
    {
        $this->actingAsUserWith(['media.view']);
        $this->image('no-alt');
        $this->image('with-alt', 'Подпись');

        $this->get(route('admin.media.index', ['usage' => 'no_alt']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('media.data', 1)
                ->where('media.data.0.original_name', 'no-alt.webp')
            );
    }

    private function image(string $name, ?string $alt = null): Media
    {
        Storage::disk('public')->put("media/{$name}.webp", 'img');

        return Media::create([
            'filename' => "media/{$name}.webp",
            'original_name' => "{$name}.webp",
            'mime_type' => 'image/webp',
            'type' => 'image',
            'size' => 3,
            'alt' => $alt,
        ]);
    }
}
