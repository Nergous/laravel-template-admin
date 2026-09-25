<?php

namespace Tests\Feature;

use App\Jobs\UploadMedia;
use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Setting;
use App\Models\User;
use App\Services\ImageOptimizer;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Media library metadata, remote-disk support, upload failure reporting and
 * settings usage warnings.
 */
class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function requireGd(): void
    {
        if (! function_exists('imagecreatefromjpeg') || ! function_exists('imagewebp')) {
            $this->markTestSkipped('GD with JPEG/WebP support is unavailable');
        }
    }

    /**
     * A fake disk that behaves like a remote one: path() is forbidden, URLs
     * point at an external origin.
     */
    private function fakeRemoteMediaDisk(): FilesystemAdapter
    {
        $fake = Storage::fake('remote');
        $remote = new class($fake->getDriver(), $fake->getAdapter(), ['url' => 'https://cdn.example.com']) extends FilesystemAdapter
        {
            public function path($path)
            {
                throw new \LogicException('path() must not be used on a remote media disk');
            }
        };

        Storage::set('remote', $remote);
        config(['media.disk' => 'remote']);

        return $remote;
    }

    /** JPEG whose left half is red and right half blue, tagged with an EXIF orientation. */
    private function jpegWithOrientation(int $width, int $height, int $orientation): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocate($image, 0, 0, 255));
        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        // Little-endian TIFF header + IFD0 with a single Orientation (0x0112, SHORT) entry.
        $tiff = "II*\x00".pack('V', 8)
            .pack('v', 1)
            .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation)."\x00\x00"
            .pack('V', 0);
        $app1 = "Exif\x00\x00".$tiff;

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    /** @return array{0:int,1:int,2:int} */
    private function rgbAt(\GdImage $image, int $x, int $y): array
    {
        $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        return [$c['red'], $c['green'], $c['blue']];
    }

    public function test_exif_orientation_is_applied_before_webp_conversion(): void
    {
        $this->requireGd();
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('exif extension is unavailable');
        }

        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('temp/rotated.jpg', $this->jpegWithOrientation(40, 20, 6));

        (new UploadMedia('temp/rotated.jpg', 'rotated.jpg'))->handle();

        $media = Media::firstWhere('original_name', 'rotated.jpg');
        $this->assertNotNull($media);
        $this->assertStringEndsWith('.webp', $media->filename);
        // Orientation 6 = rotate 90° clockwise: a 40×20 landscape becomes 20×40.
        $this->assertSame([20, 40], [$media->width, $media->height]);

        $decoded = imagecreatefromstring(Storage::disk('public')->get($media->filename));
        // The stored left (red) half ends up on top, the right (blue) half at the bottom.
        [$r, , $b] = $this->rgbAt($decoded, 10, 5);
        $this->assertGreaterThan(200, $r);
        $this->assertLessThan(60, $b);
        [$r, , $b] = $this->rgbAt($decoded, 10, 35);
        $this->assertLessThan(60, $r);
        $this->assertGreaterThan(200, $b);
    }

    public function test_upload_job_works_on_a_remote_media_disk(): void
    {
        $this->requireGd();
        Storage::fake('local');
        $remote = $this->fakeRemoteMediaDisk();

        $tempPath = UploadedFile::fake()->image('big.jpg', 1200, 900)->store('temp', 'local');
        (new UploadMedia($tempPath, 'big.jpg'))->handle();

        $media = Media::firstWhere('original_name', 'big.jpg');
        $this->assertNotNull($media);
        $remote->assertExists($media->filename);
        $remote->assertExists(ImageOptimizer::thumbPath($media->filename));
        $this->assertTrue($media->has_thumb);
        $this->assertSame([1200, 900], [$media->width, $media->height]);
        $this->assertStringStartsWith('https://cdn.example.com/media/', $media->url());

        // Non-images are streamed as-is.
        Storage::disk('local')->put('temp/doc.pdf', '%PDF-1.4 remote');
        (new UploadMedia('temp/doc.pdf', 'doc.pdf'))->handle();
        $doc = Media::firstWhere('original_name', 'doc.pdf');
        $this->assertSame('%PDF-1.4 remote', $remote->get($doc->filename));

        // Details, thumbnail backfill and deletion also avoid path().
        $legacy = Media::create(['filename' => $media->filename, 'type' => 'image', 'mime_type' => 'image/webp']);
        $this->actingAsUserWith(['media.view', 'media.delete']);
        $this->getJson(route('admin.media.show', $legacy))
            ->assertOk()
            ->assertJsonPath('dimensions', ['width' => 1200, 'height' => 900]);

        $this->delete(route('admin.media.destroy', $media))->assertRedirect();
        $remote->assertMissing($media->filename);
    }

    public function test_csp_allows_an_external_media_disk_origin(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $csp = $this->get(route('login'))->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("media-src 'self'", $csp);
        $this->assertStringNotContainsString('cdn.example.com', $csp);

        $this->fakeRemoteMediaDisk();
        $csp = $this->get(route('login'))->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("#img-src 'self' data:[^;]* https://cdn\.example\.com#", $csp);
        $this->assertStringContainsString("media-src 'self' https://cdn.example.com", $csp);
    }

    public function test_upload_job_logs_as_the_uploader(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $uploader = $this->actingAsUserWith(['media.upload']);
        $this->app['auth']->forgetGuards(); // the queue worker has no session user

        Storage::disk('local')->put('temp/note.txt', 'hello');
        (new UploadMedia('temp/note.txt', 'note.txt', $uploader->id))->handle();

        $media = Media::firstWhere('original_name', 'note.txt');
        $this->assertSame($uploader->id, $media->created_by);
        $log = ActivityLog::where('action', 'created')->where('subject_type', Media::class)->first();
        $this->assertSame($uploader->id, $log->user_id);
    }

    public function test_failed_upload_is_logged_and_reported_by_the_poll(): void
    {
        Storage::fake('local');
        $uploader = $this->actingAsUserWith(['media.view']);
        $batch = (string) Str::uuid();

        Storage::disk('local')->put('temp/broken.jpg', 'x');
        (new UploadMedia('temp/broken.jpg', 'broken.jpg', $uploader->id, null, $batch))
            ->failed(new \RuntimeException('Disk unavailable'));

        Storage::disk('local')->assertMissing('temp/broken.jpg');
        $log = ActivityLog::firstWhere('action', 'upload_failed');
        $this->assertSame($uploader->id, $log->user_id);
        $this->assertSame('broken.jpg', $log->subject_label);

        $this->getJson(route('admin.media.poll', ['batch' => $batch]))
            ->assertOk()
            ->assertJsonPath('items', [])
            ->assertJsonPath('failed.0.name', 'broken.jpg')
            ->assertJsonPath('failed.0.error', 'Disk unavailable');

        // Another upload request never sees this failure.
        $this->getJson(route('admin.media.poll', ['batch' => (string) Str::uuid()]))->assertJsonPath('failed', []);
    }

    public function test_poll_returns_only_items_of_the_batch(): void
    {
        $this->actingAsUserWith(['media.view']);
        $batch = (string) Str::uuid();
        Media::create(['filename' => 'media/old.txt', 'original_name' => 'old.txt']);
        $mine = Media::create(['filename' => 'media/mine.txt', 'original_name' => 'mine.txt', 'upload_batch' => $batch]);
        Media::create(['filename' => 'media/other.txt', 'original_name' => 'other.txt', 'upload_batch' => (string) Str::uuid()]);

        $this->getJson(route('admin.media.poll', ['batch' => $batch]))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $mine->id);
    }

    public function test_store_and_replace_return_a_batch_id(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUserWith(['media.upload', 'media.edit']);
        $media = Media::create(['filename' => 'media/a.txt', 'original_name' => 'a.txt']);

        $batch = $this->post(route('admin.media.store'), ['media' => [UploadedFile::fake()->create('b.txt', 1, 'text/plain')]])
            ->assertOk()
            ->assertJsonPath('queued', 1)
            ->json('batch');
        $this->assertTrue(Str::isUuid($batch));

        $this->post(route('admin.media.replace', $media), ['file' => UploadedFile::fake()->create('c.txt', 1, 'text/plain')])
            ->assertOk()
            ->assertJsonStructure(['queued', 'batch']);

        Queue::assertPushed(UploadMedia::class, 2);
    }

    public function test_upload_rejects_more_files_than_the_advertised_limit(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUserWith(['media.upload']);

        $files = array_map(fn (int $i) => UploadedFile::fake()->create("f{$i}.txt", 1, 'text/plain'), range(1, 11));

        $this->postJson(route('admin.media.store'), ['media' => $files])
            ->assertStatus(422)
            ->assertJsonValidationErrors('media');
        Queue::assertNothingPushed();
    }

    public function test_update_saves_alt_and_folder(): void
    {
        $this->actingAsUserWith(['media.edit']);
        $media = Media::create(['filename' => 'media/a.webp', 'original_name' => 'a.webp']);

        $this->patch(route('admin.media.update', $media), ['alt' => '  Логотип  ', 'folder' => 'Бренд'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $media->refresh();
        $this->assertSame(['Логотип', 'Бренд', 'a.webp'], [$media->alt, $media->folder, $media->original_name]);

        $this->patch(route('admin.media.update', $media), ['alt' => '', 'folder' => ''])->assertSessionHasNoErrors();
        $media->refresh();
        $this->assertNull($media->alt);
        $this->assertNull($media->folder);

        $this->patch(route('admin.media.update', $media), ['folder' => 'a/b'])->assertSessionHasErrors('folder');
        $this->patch(route('admin.media.update', $media), ['original_name' => ''])->assertSessionHasErrors('original_name');
    }

    public function test_bulk_folder_moves_files_and_logs_each_change(): void
    {
        $this->actingAsUserWith(['media.edit']);
        $a = Media::create(['filename' => 'media/a.webp']);
        $b = Media::create(['filename' => 'media/b.webp', 'folder' => 'Старое']);
        $c = Media::create(['filename' => 'media/c.webp']);

        $this->patch(route('admin.media.bulk-folder'), ['ids' => [$a->id, $b->id], 'folder' => 'Баннеры'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Перемещено файлов: 2');

        $this->assertSame('Баннеры', $a->fresh()->folder);
        $this->assertSame('Баннеры', $b->fresh()->folder);
        $this->assertNull($c->fresh()->folder);
        $this->assertSame(2, ActivityLog::where('action', 'updated')->where('subject_type', Media::class)->count());

        $this->patch(route('admin.media.bulk-folder'), ['ids' => [$a->id], 'folder' => ''])->assertRedirect();
        $this->assertNull($a->fresh()->folder);

        $this->patch(route('admin.media.bulk-folder'), ['ids' => [$a->id], 'folder' => '../x'])
            ->assertSessionHasErrors('folder');
    }

    public function test_bulk_folder_requires_edit_permission(): void
    {
        $this->actingAsUserWith(['media.view', 'media.delete']);
        $media = Media::create(['filename' => 'media/a.webp']);

        $this->patch(route('admin.media.bulk-folder'), ['ids' => [$media->id], 'folder' => 'X'])->assertForbidden();
        $this->assertNull($media->fresh()->folder);
    }

    public function test_index_filters_by_folder_and_counts_types(): void
    {
        $this->actingAsUserWith(['media.view']);
        Media::create(['filename' => 'media/1.webp', 'type' => 'image', 'folder' => 'Баннеры']);
        Media::create(['filename' => 'media/2.webp', 'type' => 'image', 'folder' => 'Баннеры']);
        Media::create(['filename' => 'media/3.pdf', 'type' => 'document', 'folder' => 'Баннеры']);
        Media::create(['filename' => 'media/4.pdf', 'type' => 'document']);

        $this->get(route('admin.media.index', ['folder' => 'Баннеры', 'type' => 'image']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Media/Index')
                ->has('media.data', 2)
                ->where('filters.folder', 'Баннеры')
                ->where('folders', ['Баннеры'])
                // Counts ignore the type filter but respect the folder.
                ->where('typeCounts', ['document' => 1, 'image' => 2])
                ->where('uploadRules.maxFiles', 10)
                ->where('uploadRules.maxSizeKb', 51200)
                ->has('uploadRules.extensions')
            );

        $this->get(route('admin.media.index', ['folder' => '__none__']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('media.data', 1)
                ->where('media.data.0.filename', 'media/4.pdf')
                ->where('typeCounts', ['document' => 1])
            );
    }

    public function test_files_used_in_settings_are_flagged(): void
    {
        Storage::fake('public');
        $this->actingAsUserWith(['media.view']);
        $icon = Media::create(['filename' => 'media/icon.webp', 'type' => 'image']);
        $og = Media::create(['filename' => 'media/og.webp', 'type' => 'image', 'has_thumb' => true]);
        $free = Media::create(['filename' => 'media/free.webp', 'type' => 'image']);

        Setting::set('general', 'favicon', $icon->url());
        Setting::set('seo', 'og_image', $og->url());

        $this->get(route('admin.media.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('media.data.0.id', $free->id)
                ->where('media.data.0.usages', [])
                ->where('media.data.1.usages', ['OG-изображение'])
                ->where('media.data.2.usages', ['Фавикон'])
            );

        $this->getJson(route('admin.media.show', $icon))->assertJsonPath('usages', ['Фавикон']);
    }

    public function test_usage_filter_splits_used_and_unused_files(): void
    {
        Storage::fake('public');
        $this->actingAsUserWith(['media.view']);
        $icon = Media::create(['filename' => 'media/icon.webp', 'type' => 'image']);
        $free = Media::create(['filename' => 'media/free.webp', 'type' => 'image']);
        Setting::set('general', 'favicon', $icon->url());

        $this->get(route('admin.media.index', ['usage' => 'used']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('media.data', 1)
                ->where('media.data.0.id', $icon->id)
                ->where('filters.usage', 'used'));
        $this->get(route('admin.media.index', ['usage' => 'unused']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('media.data', 1)
                ->where('media.data.0.id', $free->id));
    }

    public function test_a_duplicate_upload_is_skipped_and_reported_by_the_poll(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $uploader = $this->actingAsUserWith(['media.view']);

        Storage::disk('local')->put('temp/one.txt', 'same bytes');
        (new UploadMedia('temp/one.txt', 'one.txt', $uploader->id, null, (string) Str::uuid()))->handle();
        $existing = Media::sole();
        $this->assertSame(hash('sha256', 'same bytes'), $existing->content_hash);

        $batch = (string) Str::uuid();
        Storage::disk('local')->put('temp/two.txt', 'same bytes');
        (new UploadMedia('temp/two.txt', 'two.txt', $uploader->id, null, $batch))->handle();

        $this->assertSame(1, Media::count());
        Storage::disk('local')->assertMissing('temp/two.txt');
        $this->getJson(route('admin.media.poll', ['batch' => $batch]))
            ->assertOk()
            ->assertJsonPath('items', [])
            ->assertJsonPath('duplicates.0.name', 'two.txt')
            ->assertJsonPath('duplicates.0.existing_id', $existing->id);
    }

    public function test_crop_replaces_the_file_and_resets_the_focal_point(): void
    {
        $this->requireGd();
        Storage::fake('public');
        $this->actingAsUserWith(['media.view', 'media.edit']);

        $image = imagecreatetruecolor(800, 400);
        ob_start();
        imagewebp($image);
        Storage::disk('public')->put('media/src.webp', (string) ob_get_clean());
        imagedestroy($image);

        $media = Media::create([
            'filename' => 'media/src.webp', 'original_name' => 'src.webp', 'mime_type' => 'image/webp',
            'type' => 'image', 'width' => 800, 'height' => 400, 'focal_x' => 0.3, 'focal_y' => 0.3,
        ]);

        $this->postJson(route('admin.media.crop', $media), ['x' => 0.8, 'y' => 0, 'width' => 0.5, 'height' => 1])
            ->assertStatus(422);

        $this->postJson(route('admin.media.crop', $media), ['x' => 0, 'y' => 0, 'width' => 0.5, 'height' => 1])
            ->assertOk()
            ->assertJsonPath('dimensions.width', 400)
            ->assertJsonPath('dimensions.height', 400)
            ->assertJsonPath('focal_x', null);

        $media->refresh();
        $this->assertNotSame('media/src.webp', $media->filename);
        Storage::disk('public')->assertMissing('media/src.webp');
        Storage::disk('public')->assertExists($media->filename);
        $this->assertSame(1, ActivityLog::where('action', 'media_cropped')->count());
    }

    public function test_focal_point_is_saved_as_a_pair(): void
    {
        $this->actingAsUserWith(['media.view', 'media.edit']);
        $media = Media::create(['filename' => 'media/a.webp', 'type' => 'image']);

        $this->patch(route('admin.media.update', $media), ['focal_x' => 0.25])->assertSessionHasErrors('focal_y');
        $this->patch(route('admin.media.update', $media), ['focal_x' => 0.25, 'focal_y' => 0.75])->assertRedirect();

        $this->assertSame([0.25, 0.75], [$media->fresh()->focal_x, $media->fresh()->focal_y]);
    }

    public function test_folders_can_be_renamed_merged_and_dissolved(): void
    {
        $this->actingAsUserWith(['media.view', 'media.edit']);
        foreach (['Old', 'Old', 'Target'] as $i => $folder) {
            Media::create(['filename' => "media/{$i}.txt", 'folder' => $folder]);
        }

        $this->patch(route('admin.media.folders.rename'), ['folder' => 'Old', 'name' => 'Target'])
            ->assertRedirect(route('admin.media.index', ['folder' => 'Target']));
        $this->assertSame(3, Media::where('folder', 'Target')->count());
        $this->assertSame(1, ActivityLog::where('action', 'folder_renamed')->count());

        $this->delete(route('admin.media.folders.clear'), ['folder' => 'Target'])->assertRedirect();
        $this->assertSame(3, Media::whereNull('folder')->count());
        $this->assertSame(1, ActivityLog::where('action', 'folder_cleared')->count());

        $this->delete(route('admin.media.folders.clear'), ['folder' => 'Missing'])->assertSessionHasErrors('folder');
    }

    public function test_prune_keeps_responsive_copies_of_live_records(): void
    {
        Storage::fake('local');
        $disk = Storage::fake('public');
        $files = ['media/keep.webp', 'media/keep.thumb.webp', 'media/keep.w960.webp', 'media/orphan.webp', 'media/orphan.w960.webp'];
        foreach ($files as $file) {
            $disk->put($file, 'x');
            touch($disk->path($file), time() - 7200);
        }
        Media::create(['filename' => 'media/keep.webp', 'has_thumb' => true, 'variants' => [960]]);

        $this->artisan('media:prune-orphans', ['--hours' => 1])->assertSuccessful();

        $disk->assertExists(['media/keep.webp', 'media/keep.thumb.webp', 'media/keep.w960.webp']);
        $disk->assertMissing(['media/orphan.webp', 'media/orphan.w960.webp']);
    }

    public function test_backfill_skips_responsive_copies(): void
    {
        $disk = Storage::fake('public');
        $disk->put('media/abc.w960.webp', 'not an image');

        $this->artisan('media:backfill-thumbs')->assertSuccessful();

        $disk->assertMissing('media/abc.w960.thumb.webp');
    }
}
