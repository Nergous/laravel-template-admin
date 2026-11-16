<?php

namespace App\Support\Media;

use App\Models\Media;

/**
 * The files of one media record: the original, the thumbnail and the responsive copies.
 * Used where files live apart from the record: the old files after a replacement
 * or crop, the new ones on rollback, the known files when looking for orphans.
 */
final class MediaFiles
{
    /** @param  list<int>  $variants  Responsive copy widths */
    public function __construct(
        public readonly string $filename,
        public readonly array $variants = [],
    ) {}

    public static function of(Media $media): self
    {
        return new self($media->filename, array_map('intval', $media->variants ?? []));
    }

    /**
     * Paths of every file of the set on the media disk. Deleting missing
     * derived files is safe.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        $paths = [$this->filename];

        $thumb = ImagePaths::thumb($this->filename);
        if ($thumb !== $this->filename) {
            $paths[] = $thumb;
        }

        foreach ($this->variants as $width) {
            $paths[] = ImagePaths::variant($this->filename, $width);
        }

        return array_values(array_unique($paths));
    }
}
