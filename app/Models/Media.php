<?php

namespace App\Models;

use App\Services\ImageOptimizer;
use App\Traits\HasSearch;
use App\Traits\LogsActivity;
use App\Traits\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Media model from the library.
 *
 * Files live on the configured media disk under the media/ directory.
 * Uploading is performed asynchronously via the UploadMedia job.
 *
 * Authorship: the TracksAuthor trait is used (creator()/editor() relations). The
 * trait will not populate created_by itself — the Media row is created in the
 * queue (UploadMedia), where Auth::id() is empty; therefore the uploader's id is
 * set in the job manually from uploaderId. updated_by is written by the trait
 * when an authorized user edits the media (for example renaming the file on the
 * frontend).
 *
 * @property int $id
 * @property string $filename File path relative to the media disk (for example: media/abc123.webp)
 * @property string|null $original_name Original file name at upload time
 * @property string|null $alt Alternative text for images
 * @property string|null $folder Library folder (a flat label; null — outside any folder)
 * @property string|null $mime_type MIME type (for example image/webp, video/mp4)
 * @property string|null $type Category: image|video|audio|document|other
 * @property int|null $size Size in bytes
 * @property int|null $width Image width in px after processing
 * @property int|null $height Image height in px after processing
 * @property float|null $focal_x Focal point, fraction of the width (0..1); null — the center
 * @property float|null $focal_y Focal point, fraction of the height (0..1); null — the center
 * @property list<int>|null $variants Widths of the responsive copies (see ImageOptimizer::variantPath())
 * @property string|null $upload_batch Upload request that produced the current file (progress polling)
 * @property string|null $content_hash SHA-256 of the uploaded file (duplicate detection)
 */
class Media extends Model
{
    use HasSearch, LogsActivity, TracksAuthor;

    protected $fillable = [
        'filename',
        'original_name',
        'alt',
        'folder',
        'mime_type',
        'type',
        'size',
        'width',
        'height',
        'has_thumb',
        'focal_x',
        'focal_y',
        'variants',
        'upload_batch',
        'content_hash',
    ];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'has_thumb' => 'boolean',
        'focal_x' => 'float',
        'focal_y' => 'float',
        'variants' => 'array',
    ];

    /**
     * Technical columns kept out of the activity log diff.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['upload_batch', 'content_hash', 'variants'];

    /**
     * Public URLs are mixed into serialization so the frontend (media library
     * cards) immediately gets links to the original and the thumbnail.
     */
    protected $appends = ['url', 'thumb_url', 'srcset'];

    public function getUrlAttribute(): string
    {
        return $this->url();
    }

    public function getThumbUrlAttribute(): string
    {
        return $this->thumbUrl();
    }

    public function getSrcsetAttribute(): string
    {
        return $this->srcset();
    }

    /**
     * Categorizes a MIME type into one of the broad groups.
     * Used at upload time to populate the type column.
     *
     * @param  string|null  $mime  File MIME type (for example image/png, video/mp4)
     * @return string Category: image|video|audio|document|other
     */
    public static function categorize(?string $mime): string
    {
        $mime = (string) $mime;

        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            str_starts_with($mime, 'text/') => 'document',
            in_array($mime, [
                'application/pdf',
                'application/msword',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ], true) => 'document',
            default => 'other',
        };
    }

    /**
     * Whether the file is an image (a thumbnail is generated for it).
     */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    /**
     * Public URL of the original file.
     */
    public function url(): string
    {
        return Storage::disk(self::diskName())->url($this->filename);
    }

    /**
     * URL of the resized version (for photos).
     */
    public function thumbUrl(): string
    {
        if (! $this->has_thumb) {
            return $this->url();
        }

        return Storage::disk(self::diskName())->url(ImageOptimizer::thumbPath($this->filename));
    }

    /**
     * srcset value for responsive images: the thumbnail, the responsive copies and
     * the original with their widths. Empty for non-images or unknown widths.
     */
    public function srcset(): string
    {
        if (! $this->isImage() || $this->width === null) {
            return '';
        }

        $disk = Storage::disk(self::diskName());
        $candidates = [];

        if ($this->has_thumb) {
            $candidates[ImageOptimizer::THUMB_MAX_WIDTH] = $this->thumbUrl();
        }

        foreach ($this->variants ?? [] as $width) {
            $candidates[(int) $width] = $disk->url(ImageOptimizer::variantPath($this->filename, (int) $width));
        }

        $candidates[$this->width] = $this->url();
        ksort($candidates);

        return collect($candidates)->map(fn (string $url, int $width) => "{$url} {$width}w")->implode(', ');
    }

    /**
     * Every file of this record on the media disk: the original, its thumbnail and
     * the responsive copies. Missing derived files are harmless to delete.
     *
     * @return list<string>
     */
    public function storedPaths(): array
    {
        $paths = [$this->filename];

        $thumb = ImageOptimizer::thumbPath($this->filename);
        if ($thumb !== $this->filename) {
            $paths[] = $thumb;
        }

        foreach ($this->variants ?? [] as $width) {
            $paths[] = ImageOptimizer::variantPath($this->filename, (int) $width);
        }

        return array_values(array_unique($paths));
    }

    /**
     * Deletes the file itself and its generated thumbnail and responsive copies.
     *
     * The single cleanup point for media files — call it from any deletion path
     * so as not to leave orphaned files.
     */
    public function deleteFiles(): void
    {
        Storage::disk(self::diskName())->delete($this->storedPaths());
    }

    /**
     * Search by the display name and the stored file name (substring).
     *
     * @param  string|null  $search  Search string
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        return $this->scopeSearchLike($query, $search, ['original_name', 'filename']);
    }

    /** Media disk name (config('media.disk'), public by default). */
    public static function diskName(): string
    {
        return (string) config('media.disk', 'public');
    }
}
