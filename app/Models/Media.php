<?php

namespace App\Models;

use App\Enums\MediaType;
use App\Models\Concerns\HasSearch;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\TracksAuthor;
use App\Observers\MediaObserver;
use App\Support\Media\ImagePaths;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A media library file.
 *
 * Files live on the media disk (config('media.disk')) in the media/ directory.
 * Uploads are processed as a job: UploadMedia → MediaUploadProcessor.
 *
 * Authorship: the TracksAuthor trait (creator()/editor() relations). The trait
 * itself does not fill created_by — the record is created in a job where
 * Auth::id() is empty, so MediaUploadProcessor sets the uploader id. The trait
 * writes updated_by when a signed-in user edits the file (e.g. a rename).
 *
 * @property int $id
 * @property string $filename Path relative to the media disk (e.g. media/abc123.webp)
 * @property string|null $original_name Original file name at upload
 * @property string|null $alt Image alt text
 * @property string|null $folder Media library folder path ("Banners/2026", see MediaFolderPath; null — outside folders)
 * @property string|null $mime_type MIME type (e.g. image/webp, video/mp4)
 * @property MediaType|null $type File kind
 * @property int|null $size Size in bytes
 * @property int|null $width Image width in px after processing
 * @property int|null $height Image height in px after processing
 * @property bool $has_thumb Whether a thumbnail exists
 * @property float|null $focal_x Focal point, fraction of the width (0..1); null — center
 * @property float|null $focal_y Focal point, fraction of the height (0..1); null — center
 * @property list<int>|null $variants Responsive copy widths (see ImagePaths::variant())
 * @property string|null $upload_batch Upload request that produced the current file (progress poll)
 * @property string|null $content_hash SHA-256 of the uploaded file (duplicate detection)
 */
#[ObservedBy(MediaObserver::class)]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory, HasSearch, LogsActivity, TracksAuthor;

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

    /**
     * Technical columns not written to the activity log (LogsActivity).
     *
     * @var list<string>
     */
    protected array $auditExclude = ['upload_batch', 'content_hash', 'variants'];

    /** @var list<string> Search by the display and the stored file name (HasSearch). */
    protected array $searchable = ['original_name', 'filename'];

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'has_thumb' => 'boolean',
            'focal_x' => 'float',
            'focal_y' => 'float',
            'variants' => 'array',
        ];
    }

    /** Whether the file is an image (it gets a thumbnail). */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    /** Public URL of the original. */
    public function url(): string
    {
        return Storage::disk(self::diskName())->url($this->filename);
    }

    /** URL of the downscaled copy (for photos); the original when there is no thumbnail. */
    public function thumbUrl(): string
    {
        if (! $this->has_thumb) {
            return $this->url();
        }

        return Storage::disk(self::diskName())->url(ImagePaths::thumb($this->filename));
    }

    /**
     * srcset value for responsive images: the thumbnail, the responsive copies and
     * the original with their widths. Empty for non-images and an unknown width.
     */
    public function srcset(): string
    {
        if (! $this->isImage() || $this->width === null) {
            return '';
        }

        $disk = Storage::disk(self::diskName());
        $candidates = [];

        if ($this->has_thumb) {
            $candidates[ImagePaths::THUMB_WIDTH] = $this->thumbUrl();
        }

        foreach ($this->variants ?? [] as $width) {
            $candidates[(int) $width] = $disk->url(ImagePaths::variant($this->filename, (int) $width));
        }

        $candidates[$this->width] = $this->url();
        ksort($candidates);

        return collect($candidates)->map(fn (string $url, int $width) => "{$url} {$width}w")->implode(', ');
    }

    /** Images without alt text (the "no_alt" filter, the dashboard card). */
    public function scopeWithoutAlt(Builder $query): Builder
    {
        return $query
            ->where('type', MediaType::Image)
            ->where(fn (Builder $alt) => $alt->whereNull('alt')->orWhere('alt', ''));
    }

    /** Media disk name (config('media.disk'), public by default). */
    public static function diskName(): string
    {
        return (string) config('media.disk', 'public');
    }
}
