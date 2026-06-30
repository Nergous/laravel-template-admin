<?php

namespace Tests\Feature;

use App\Jobs\UploadMedia;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_browse_returns_paginated_json_for_picker(): void
    {
        $this->actingAsUserWith(['media.view']);
        Media::create(['filename' => 'media/a.webp', 'original_name' => 'a.webp', 'type' => 'image']);
        $b = Media::create(['filename' => 'media/b.pdf', 'original_name' => 'b.pdf', 'type' => 'document']);

        $this->getJson(route('admin.media.browse'))
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'url', 'thumb_url', 'type', 'original_name', 'size']],
                'current_page',
                'last_page',
            ])
            ->assertJsonPath('data.0.id', $b->id) // newest first
            ->assertJsonCount(2, 'data');
    }

    public function test_browse_filters_by_search(): void
    {
        $this->actingAsUserWith(['media.view']);
        Media::create(['filename' => 'media/report.pdf', 'original_name' => 'report.pdf']);
        Media::create(['filename' => 'media/photo.webp', 'original_name' => 'photo.webp']);

        $this->getJson(route('admin.media.browse', ['search' => 'report']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.original_name', 'report.pdf');
    }

    public function test_browse_forbidden_without_view_permission(): void
    {
        $this->actingAsUserWith([]);

        $this->getJson(route('admin.media.browse'))->assertForbidden();
    }

    public function test_upload_queues_a_job_per_file(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUserWith(['media.upload']);

        $this->post(route('admin.media.store'), [
            'media' => [
                UploadedFile::fake()->image('one.jpg'),
                UploadedFile::fake()->image('two.jpg'),
            ],
        ])->assertOk()->assertJson(['queued' => 2]);

        Queue::assertPushed(UploadMedia::class, 2);
    }

    public function test_non_image_files_are_accepted(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUserWith(['media.upload']);

        $this->post(route('admin.media.store'), [
            'media' => [
                UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('clip.mp4', 200, 'video/mp4'),
            ],
        ])->assertOk()->assertJson(['queued' => 2]);

        Queue::assertPushed(UploadMedia::class, 2);
    }

    public function test_oversized_dimension_image_is_rejected(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsUserWith(['media.upload']);

        $bomb = UploadedFile::fake()->createWithContent('bomb.png', $this->pngWithDimensions(30000, 30000));

        $this->postJson(route('admin.media.store'), ['media' => [$bomb]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['media.0' => 'Слишком большое разрешение изображения']);

        Queue::assertNothingPushed();
    }

    private function pngWithDimensions(int $width, int $height): string
    {
        $ihdrData = pack('N2', $width, $height)."\x08\x06\x00\x00\x00"; // 8 bits, RGBA
        $ihdr = pack('N', strlen($ihdrData)).'IHDR'.$ihdrData.pack('N', crc32('IHDR'.$ihdrData));
        $iend = pack('N', 0).'IEND'.pack('N', crc32('IEND'));

        return "\x89PNG\r\n\x1a\n".$ihdr.$iend;
    }

    public function test_upload_job_is_hardened_for_the_queue(): void
    {
        $job = new UploadMedia('temp/x.jpg');

        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertSame([10, 30, 60], $job->backoff());
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
    }

    public function test_upload_job_is_idempotent_and_cleans_temp_on_success(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('temp/note.txt', 'hello world');

        $job = new UploadMedia('temp/note.txt', 'note.txt');
        $job->handle();

        $this->assertDatabaseCount('media', 1);
        Storage::disk('local')->assertMissing('temp/note.txt');

        $job->handle();
        $this->assertDatabaseCount('media', 1);
    }

    public function test_media_can_be_deleted_with_its_file(): void
    {
        Storage::fake('public');
        $this->actingAsUserWith(['media.delete']);

        $media = Media::create(['filename' => 'media/example.jpg']);
        Storage::disk('public')->put('media/example.jpg', 'binary');

        $this->delete(route('admin.media.destroy', $media))->assertRedirect();

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        Storage::disk('public')->assertMissing('media/example.jpg');
    }

    public function test_deleting_image_media_also_removes_its_thumbnail(): void
    {
        Storage::fake('public');
        $this->actingAsUserWith(['media.delete']);

        $media = Media::create(['filename' => 'media/photo.webp']);
        Storage::disk('public')->put('media/photo.webp', 'orig');
        Storage::disk('public')->put('media/photo.thumb.webp', 'thumb');

        $this->delete(route('admin.media.destroy', $media))->assertRedirect();

        Storage::disk('public')->assertMissing('media/photo.webp');
        Storage::disk('public')->assertMissing('media/photo.thumb.webp');
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_bulk_delete_removes_files_and_thumbnails(): void
    {
        Storage::fake('public');
        $this->actingAsUserWith(['media.delete']);

        $ids = collect(['a', 'b'])->map(function (string $name) {
            Storage::disk('public')->put("media/{$name}.webp", 'orig');
            Storage::disk('public')->put("media/{$name}.thumb.webp", 'thumb');

            return Media::create(['filename' => "media/{$name}.webp"])->id;
        })->all();

        $this->delete(route('admin.media.bulk-destroy'), ['ids' => $ids])->assertRedirect();

        foreach (['a', 'b'] as $name) {
            Storage::disk('public')->assertMissing("media/{$name}.webp");
            Storage::disk('public')->assertMissing("media/{$name}.thumb.webp");
        }
        $this->assertDatabaseCount('media', 0);
    }

    public function test_bulk_delete_rolls_back_and_keeps_files_on_failure(): void
    {
        Storage::fake('public');
        $this->actingAsUserWith(['media.delete']);

        $ids = collect(['a', 'b'])->map(function (string $name) {
            Storage::disk('public')->put("media/{$name}.webp", 'orig');

            return Media::create(['filename' => "media/{$name}.webp"])->id;
        })->all();

        Media::deleting(function () {
            throw new \RuntimeException('boom');
        });

        try {
            $this->delete(route('admin.media.bulk-destroy'), ['ids' => $ids]);
        } catch (\Throwable) {
            // expected
        }

        $this->assertDatabaseCount('media', 2);
        Storage::disk('public')->assertExists('media/a.webp');
        Storage::disk('public')->assertExists('media/b.webp');
    }

    public function test_media_index_renders_inertia_page(): void
    {
        \Spatie\Permission\Models\Permission::findOrCreate('media.view', 'web');
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('media.view');

        $this->actingAs($user)
            ->get('/admin/media')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Media/Index')
                ->has('media.data')
            );
    }

    public function test_thumb_url_is_derived_from_flag_without_touching_disk(): void
    {
        Storage::fake('public');

        $withThumb = Media::create(['filename' => 'media/photo.webp', 'has_thumb' => true]);
        $withoutThumb = Media::create(['filename' => 'media/plain.webp', 'has_thumb' => false]);

        $this->assertSame(Storage::url('media/photo.thumb.webp'), $withThumb->thumbUrl());
        $this->assertSame(Storage::url('media/plain.webp'), $withoutThumb->thumbUrl());

        $this->assertSame(Storage::url('media/photo.thumb.webp'), $withThumb->toArray()['thumb_url']);
    }

    public function test_non_image_copy_streams_contents_byte_for_byte(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $payload = str_repeat('video-bytes-', 4096);
        Storage::disk('local')->put('temp/clip.mp4', $payload);

        (new UploadMedia('temp/clip.mp4', 'clip.mp4'))->handle();

        $media = Media::firstWhere('original_name', 'clip.mp4');
        $this->assertNotNull($media);
        $this->assertSame('mp4', pathinfo($media->filename, PATHINFO_EXTENSION));
        $this->assertSame($payload, Storage::disk('public')->get($media->filename));
    }

    public function test_upload_job_never_derives_saved_extension_from_client_name(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('temp/upload.pdf', '%PDF-1.4 fake');

        (new UploadMedia('temp/upload.pdf', 'shell.php'))->handle();

        $media = Media::firstWhere('original_name', 'shell.php');
        $this->assertNotNull($media);
        $this->assertStringEndsNotWith('.php', $media->filename);
        $this->assertSame('pdf', pathinfo($media->filename, PATHINFO_EXTENSION));
        Storage::disk('public')->assertExists($media->filename);
    }

    public function test_upload_through_controller_stores_safe_extension_for_spoofed_name(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        config(['queue.default' => 'sync']);
        $this->actingAsUserWith(['media.upload']);

        $this->post(route('admin.media.store'), [
            'media' => [
                UploadedFile::fake()->create('x.html', 10, 'application/pdf'),
            ],
        ])->assertOk()->assertJson(['queued' => 1]);

        $media = Media::firstWhere('original_name', 'x.html');
        $this->assertNotNull($media);
        $this->assertStringEndsNotWith('.html', $media->filename);
        Storage::disk('public')->assertExists($media->filename);
    }

    public function test_image_optimizer_fallback_streams_original_when_decode_fails(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $payload = str_repeat('not-a-real-png-', 4096);
        Storage::disk('local')->put('temp/broken.png', $payload);

        $filename = \App\Services\ImageOptimizer::storeFromDisk(
            sourcePath: 'temp/broken.png',
            sourceDisk: 'local',
            directory: 'media',
        );

        $this->assertSame('png', pathinfo($filename, PATHINFO_EXTENSION));
        $this->assertSame($payload, Storage::disk('public')->get($filename));
    }

    public function test_upload_job_marks_non_image_without_thumbnail(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('local')->put('temp/note.txt', 'hello world');

        (new UploadMedia('temp/note.txt', 'note.txt'))->handle();

        $this->assertFalse(Media::firstWhere('original_name', 'note.txt')->has_thumb);
    }

    public function test_upload_job_marks_image_with_generated_thumbnail(): void
    {
        if (! function_exists('imagecreatefromjpeg') || ! function_exists('imagewebp')) {
            $this->markTestSkipped('GD с поддержкой JPEG/WebP недоступен');
        }

        Storage::fake('local');
        Storage::fake('public');

        $tempPath = UploadedFile::fake()->image('big.jpg', 1200, 900)->store('temp', 'local');

        (new UploadMedia($tempPath, 'big.jpg'))->handle();

        $media = Media::firstWhere('original_name', 'big.jpg');
        $this->assertNotNull($media);
        $this->assertTrue($media->has_thumb);
        Storage::disk('public')->assertExists(\App\Services\ImageOptimizer::thumbPath($media->filename));
    }

    public function test_backfill_marks_existing_rows_that_already_have_thumbnails(): void
    {
        Storage::fake('public');

        Storage::disk('public')->put('media/legacy.webp', 'orig');
        Storage::disk('public')->put('media/legacy.thumb.webp', 'thumb');
        $media = Media::create(['filename' => 'media/legacy.webp', 'has_thumb' => false]);

        $this->artisan('media:backfill-thumbs')->assertSuccessful();

        $this->assertTrue($media->fresh()->has_thumb);
    }
}
