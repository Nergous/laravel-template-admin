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
 * Files live on the public disk under the media/ directory.
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
 * @property string $filename File path relative to the public disk (for example: media/abc123.webp)
 * @property string|null $original_name Original file name at upload time
 * @property string|null $mime_type MIME type (for example image/webp, video/mp4)
 * @property string|null $type Category: image|video|audio|document|other
 * @property int|null $size Size in bytes
 */
class Media extends Model
{
    use HasSearch, LogsActivity, TracksAuthor;

    protected $fillable = ['filename', 'original_name', 'mime_type', 'type', 'size', 'has_thumb'];

    protected $casts = [
        'size' => 'integer',
        'has_thumb' => 'boolean',
    ];

    /**
     * Public URLs are mixed into serialization so the frontend (media library
     * cards) immediately gets links to the original and the thumbnail.
     */
    protected $appends = ['url', 'thumb_url'];

    public function getUrlAttribute(): string
    {
        return $this->url();
    }

    public function getThumbUrlAttribute(): string
    {
        return $this->thumbUrl();
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
        return Storage::url($this->filename);
    }

    /**
     * URL of the resized version (for photos).
     */
    public function thumbUrl(): string
    {
        if (! $this->has_thumb) {
            return $this->url();
        }

        return Storage::url(ImageOptimizer::thumbPath($this->filename));
    }

    /**
     * Deletes the file itself and its generated thumbnail from the public disk.
     *
     * The single cleanup point for media files — call it from any deletion path
     * so as not to leave orphaned files. For non-images (and the GD-less fallback)
     * thumbPath() returns the path itself — in that case we skip the second delete.
     */
    public function deleteFiles(): void
    {
        $disk = Storage::disk('public');
        $disk->delete($this->filename);

        $thumb = ImageOptimizer::thumbPath($this->filename);
        if ($thumb !== $this->filename) {
            $disk->delete($thumb);
        }
    }

    /**
     * Search by file name (substring).
     *
     * @param  string|null  $search  Search string
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        return $this->scopeSearchLike($query, $search, ['filename']);
    }
}
