<?php

namespace Tests\Feature;

use App\Jobs\UploadMedia;
use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\User;
use App\Services\Media\MediaCropper;
use App\Services\Media\MediaReferenceRegistry;
use App\Services\Media\MediaReferenceUpdater;
use App\Services\Media\MediaService;
use App\Services\Media\MediaUsage;
use App\Services\System\Settings;
use App\Support\Media\MediaFiles;
use App\Support\Selection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Integrity of media references: a file change switches the record and every
 * link together or not at all, every registered place counts as usage, and
 * the usage scan stays cheap.
 */
class MediaReferenceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_link_rewrite_on_replace_keeps_the_record_and_the_old_file(): void
    {
        Storage::fake('local');
        $disk = Storage::fake('public');
        $media = $this->file('old.txt', 'document', 'text/plain');
        Storage::disk('local')->put('temp/new.txt', 'new content');
        $this->mock(MediaReferenceUpdater::class, fn ($mock) => $mock->shouldReceive('rewrite')->andThrow(new \RuntimeException('rewrite failed')));

        try {
            app()->call([new UploadMedia('temp/new.txt', 'new.txt', null, $media->id), 'handle']);
            $this->fail('The job should rethrow the rewrite failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('rewrite failed', $e->getMessage());
        }

        $this->assertSame('media/old.txt', $media->fresh()->filename);
        $this->assertSame(['media/old.txt'], $disk->allFiles('media'), 'the old file stays, the new one is removed');
        Storage::disk('local')->assertExists('temp/new.txt');
    }

    public function test_replace_switches_links_and_removes_the_old_file_after_commit(): void
    {
        Storage::fake('local');
        $disk = Storage::fake('public');
        $media = $this->file('old.txt', 'document', 'text/plain');
        app(Settings::class)->set('seo', 'og_image', $media->url());
        Storage::disk('local')->put('temp/new.txt', 'new content');

        app()->call([new UploadMedia('temp/new.txt', 'new.txt', null, $media->id), 'handle']);

        $new = $media->fresh();
        $this->assertNotSame('media/old.txt', $new->filename);
        $disk->assertMissing('media/old.txt');
        $disk->assertExists($new->filename);
        $this->assertSame($new->url(), app(Settings::class)->value('seo', 'og_image'));
    }

    public function test_failed_link_rewrite_on_crop_keeps_the_record_and_the_old_files(): void
    {
        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('GD with WebP support is unavailable');
        }

        $disk = Storage::fake('public');
        $image = imagecreatetruecolor(800, 600);
        ob_start();
        imagewebp($image);
        $disk->put('media/pic.webp', (string) ob_get_clean());
        $media = Media::create(['filename' => 'media/pic.webp', 'type' => 'image', 'mime_type' => 'image/webp', 'width' => 800, 'height' => 600]);
        $this->mock(MediaReferenceUpdater::class, fn ($mock) => $mock->shouldReceive('rewrite')->andThrow(new \RuntimeException('rewrite failed')));

        try {
            app(MediaCropper::class)->crop($media, ['x' => 0, 'y' => 0, 'width' => 0.5, 'height' => 0.5], User::factory()->create());
            $this->fail('The crop should rethrow the rewrite failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('rewrite failed', $e->getMessage());
        }

        $this->assertSame('media/pic.webp', $media->fresh()->filename);
        $this->assertSame(['media/pic.webp'], $disk->allFiles('media'));
    }

    public function test_rewrite_covers_every_registered_setting_and_logs_it(): void
    {
        Storage::fake('public');
        $old = $this->file('before.webp', 'image', 'image/webp');
        $url = $old->url();
        app(Settings::class)->set('general', 'favicon', 'https://cdn.example.test'.$url);
        app(Settings::class)->set('seo', 'og_image', $url);

        $new = (clone $old)->forceFill(['filename' => 'media/after.webp']);
        $changed = app(MediaReferenceUpdater::class)->rewrite(MediaFiles::of($old), $new, null);

        $this->assertSame(2, $changed);
        $newUrl = $new->url();
        $this->assertSame('https://cdn.example.test'.$newUrl, app(Settings::class)->value('general', 'favicon'));
        $this->assertSame($newUrl, app(Settings::class)->value('seo', 'og_image'));
        $this->assertTrue(ActivityLog::where('action', 'settings_updated')->exists());
    }

    public function test_every_registered_setting_counts_as_usage_and_blocks_deletion(): void
    {
        Storage::fake('public');
        $icon = $this->file('icon.webp', 'image', 'image/webp');
        $og = $this->file('og.webp', 'image', 'image/webp');
        $free = $this->file('free.webp', 'image', 'image/webp');
        app(Settings::class)->set('general', 'favicon', $icon->url());
        app(Settings::class)->set('seo', 'og_image', $og->url());

        $usages = app(MediaUsage::class)->for([$icon, $og, $free]);

        $this->assertSame(['Фавикон'], $usages[$icon->id]);
        $this->assertSame(['OG-изображение'], $usages[$og->id]);
        $this->assertSame([], $usages[$free->id]);

        $result = app(MediaService::class)->bulkDelete(Selection::ids([$icon->id, $og->id, $free->id]));
        $this->assertSame(1, $result['deleted']);
        $this->assertCount(2, $result['skipped']);
    }

    public function test_bulk_delete_scans_text_once_for_any_number_of_files(): void
    {
        Storage::fake('public');
        $ids = collect(range(1, 25))->map(fn (int $i) => $this->file("f{$i}.webp", 'image', 'image/webp')->id)->all();

        DB::enableQueryLog();
        app(MediaService::class)->bulkDelete(Selection::ids($ids));
        $scans = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains(strtolower($q['query']), ' like '))->count();
        DB::disableQueryLog();

        $this->assertSame(count(MediaReferenceRegistry::TEXT_FIELDS), $scans);
    }

    public function test_replace_requires_the_same_type(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUserWith(['media.edit']);
        $image = Media::create(['filename' => 'media/a.webp', 'type' => 'image', 'mime_type' => 'image/webp']);

        $this->post(route('admin.media.replace', $image), ['file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors(['file' => 'Изображение можно заменить только изображением']);
        $this->post(route('admin.media.replace', $image), ['file' => UploadedFile::fake()->image('b.png', 10, 10)])
            ->assertOk();

        Queue::assertPushed(UploadMedia::class, 1);
    }

    public function test_avif_upload_is_accepted(): void
    {
        if (! function_exists('imageavif')) {
            $this->markTestSkipped('GD without AVIF support');
        }

        Storage::fake('local');
        Queue::fake();
        $this->actingAsUserWith(['media.upload']);
        $path = tempnam(sys_get_temp_dir(), 'avif');
        imageavif(imagecreatetruecolor(16, 16), $path);

        $this->post(route('admin.media.store'), ['media' => [new UploadedFile($path, 'photo.avif', 'image/avif', null, true)]])
            ->assertOk()
            ->assertJsonPath('queued', 1);

        @unlink($path);
    }

    public function test_prune_keeps_temp_files_of_queued_jobs(): void
    {
        $local = Storage::fake('local');
        Storage::fake('public');
        config(['queue.default' => 'database']);
        foreach (['temp/queued.jpg', 'temp/stale.jpg'] as $file) {
            $local->put($file, 'x');
            touch($local->path($file), time() - 3 * 86400);
        }
        Queue::connection('database')->push(new UploadMedia('temp/queued.jpg', 'queued.jpg'));

        $this->artisan('media:prune-orphans', ['--hours' => 1])->assertSuccessful();

        $local->assertExists('temp/queued.jpg');
        $local->assertMissing('temp/stale.jpg');
    }

    private function file(string $name, string $type, string $mime): Media
    {
        Storage::disk('public')->put("media/{$name}", 'bytes');

        return Media::create([
            'filename' => "media/{$name}",
            'original_name' => $name,
            'type' => $type,
            'mime_type' => $mime,
            'size' => 5,
        ]);
    }
}
