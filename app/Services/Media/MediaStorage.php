<?php

namespace App\Services\Media;

use App\Enums\ImageFormat;
use App\Enums\MediaType;
use App\Models\Media;
use App\Support\Media\ImagePaths;
use App\Support\Media\MediaFiles;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Media files on the disks: the media disk (Media::diskName(), possibly
 * remote, e.g. S3) and the local disk of temporary uploads.
 *
 * Files are read and written as streams; GD and getimagesize read them through
 * a temporary local copy: ->path() of the media disk is never used.
 */
class MediaStorage
{
    /** Disk where a request puts an upload before it is processed by the queue. */
    public const TEMP_DISK = 'local';

    /** Directory of temporary uploads on TEMP_DISK. */
    public const TEMP_DIRECTORY = 'temp';

    public function __construct(private readonly FilesystemFactory $storage) {}

    /** The media disk; the name is read on every call, so a disk change in the settings applies at once. */
    public function disk(): Filesystem
    {
        return $this->storage->disk(Media::diskName());
    }

    /** Local disk of temporary uploads. */
    public function temp(): Filesystem
    {
        return $this->storage->disk(self::TEMP_DISK);
    }

    /**
     * Copies a file from $sourceDisk to $directory on the media disk without
     * processing: a random name, the same extension. Streamed: the original is
     * never loaded into memory as a whole.
     *
     * @return string Path of the new file relative to the media disk
     *
     * @throws RuntimeException When the file cannot be read or written.
     */
    public function copy(string $sourceDisk, string $sourcePath, string $directory): string
    {
        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $filename = $directory.'/'.Str::random(32).($extension !== '' ? '.'.$extension : '');

        $stream = $this->storage->disk($sourceDisk)->readStream($sourcePath);
        if (! is_resource($stream)) {
            throw new RuntimeException('Не удалось прочитать файл: '.$sourcePath);
        }

        try {
            // writeStream does not close the stream, so close it here (as Laravel does in putFileAs).
            if (! $this->disk()->writeStream($filename, $stream)) {
                throw new RuntimeException('Не удалось записать файл на диск медиатеки.');
            }
        } finally {
            fclose($stream);
        }

        return $filename;
    }

    /**
     * Streams a file from the disk into a temporary local file and passes its
     * path to $callback; the temporary file is deleted afterwards.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     *
     * @throws RuntimeException When the local copy cannot be created.
     */
    public function withLocalCopy(string $diskName, string $path, callable $callback): mixed
    {
        $source = $this->storage->disk($diskName)->readStream($path);
        $temp = tmpfile();

        if (! is_resource($source) || $temp === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($temp)) {
                fclose($temp);
            }

            throw new RuntimeException('Не удалось создать локальную копию файла: '.$path);
        }

        try {
            stream_copy_to_stream($source, $temp);
            fflush($temp);

            return $callback(stream_get_meta_data($temp)['uri']);
        } finally {
            fclose($source);
            fclose($temp);
        }
    }

    /**
     * Image dimensions from the file header on the media disk (without a full
     * decode); null when the file is missing or not an image.
     *
     * @return array{width: int, height: int}|null
     */
    public function dimensions(string $filename): ?array
    {
        if (! $this->disk()->exists($filename)) {
            return null;
        }

        return $this->withLocalCopy(Media::diskName(), $filename, function (string $localPath) {
            $info = @getimagesize($localPath);

            return $info ? ['width' => $info[0], 'height' => $info[1]] : null;
        });
    }

    /**
     * Metadata of a file already on the media disk. For a file re-encoded to
     * WebP or AVIF the type comes from the result, not from the source
     * ($sourceMime).
     *
     * @return array{filename: string, mime_type: ?string, type: MediaType, size: ?int, width: ?int, height: ?int, has_thumb: bool}
     */
    public function attributes(string $filename, ?string $sourceMime = null): array
    {
        $disk = $this->disk();
        $mime = ImageFormat::fromPath($filename)?->mime() ?? $sourceMime;
        $thumb = ImagePaths::thumb($filename);
        $dimensions = $mime !== null && str_starts_with($mime, 'image/') ? $this->dimensions($filename) : null;

        return [
            'filename' => $filename,
            'mime_type' => $mime,
            'type' => MediaType::fromMime($mime),
            'size' => $disk->exists($filename) ? $disk->size($filename) : null,
            'width' => $dimensions['width'] ?? null,
            'height' => $dimensions['height'] ?? null,
            'has_thumb' => $thumb !== $filename && $disk->exists($thumb),
        ];
    }

    /** Deletes a set of files from the media disk. */
    public function delete(MediaFiles $files): void
    {
        $this->disk()->delete($files->paths());
    }
}
