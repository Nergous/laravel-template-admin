<?php

namespace App\Services\Media;

use App\Events\ActivityOccurred;
use App\Models\Media;
use App\Models\User;
use App\Support\Media\MediaFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Crops a media library image. The result is stored as a new file (with its
 * own thumbnail and responsive copies), so the file URL changes, as on
 * replacement in MediaUploadProcessor.
 *
 * Same order: the new files are written, the record and every link switch in
 * one transaction, and the old files are deleted only after the commit. On
 * failure the switch rolls back and the new files are deleted instead.
 */
class MediaCropper
{
    public function __construct(
        private readonly ImageOptimizer $optimizer,
        private readonly MediaStorage $storage,
        private readonly MediaReferenceUpdater $references,
    ) {}

    /**
     * Crops the image to a normalized area on behalf of the actor; logs
     * media_cropped.
     *
     * @param  array{x: float, y: float, width: float, height: float}  $area
     *
     * @throws ValidationException When the file is not an image that can be processed.
     */
    public function crop(Media $media, array $area, User $actor): Media
    {
        if (! $media->isImage()) {
            throw ValidationException::withMessages(['crop' => 'Обрезать можно только изображения']);
        }

        try {
            $result = $this->optimizer->crop($media->filename, $area);
        } catch (RuntimeException $e) {
            report($e);

            throw ValidationException::withMessages(['crop' => $e->getMessage()]);
        }

        $old = MediaFiles::of($media);
        $new = new MediaFiles($result['filename'], $result['variants']);
        $attributes = $this->storage->attributes($new->filename);

        try {
            DB::transaction(function () use ($media, $old, $new, $attributes, $area, $actor) {
                // No model events: the only log entry is media_cropped below.
                Media::withoutEvents(fn () => $media->forceFill([
                    ...$attributes,
                    'variants' => $new->variants === [] ? null : $new->variants,
                    'focal_x' => null,
                    'focal_y' => null,
                    'updated_by' => $actor->id,
                ])->save());

                $links = $this->references->rewrite($old, $media, $actor);

                ActivityOccurred::by($actor, $media, 'media_cropped', [
                    'crop' => [null, sprintf('x %.3f, y %.3f, %.3f × %.3f', $area['x'], $area['y'], $area['width'], $area['height'])],
                    'width' => [null, $media->width],
                    'height' => [null, $media->height],
                    ...($links > 0 ? ['links_updated' => [null, $links]] : []),
                ]);
            });
        } catch (Throwable $e) {
            // Rollback: the record and the links still point at the old files.
            $this->storage->delete($new);

            throw $e;
        }

        DB::afterCommit(fn () => $this->storage->delete($old));

        return $media;
    }
}
