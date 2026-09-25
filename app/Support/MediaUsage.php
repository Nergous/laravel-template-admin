<?php

namespace App\Support;

use App\Models\Media;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves human-readable places that reference media URLs. Projects can bind a
 * subclass and extend definitions() with entity-specific references.
 */
class MediaUsage
{
    /**
     * @param  iterable<int, Media>  $media
     * @return array<int, list<string>>
     */
    public function for(iterable $media): array
    {
        $definitions = $this->definitions();
        $resolved = [];

        foreach ($definitions as $label => $value) {
            $normalized = $this->normalize($value());
            if ($normalized !== null) {
                $resolved[$label] = $normalized;
            }
        }

        return Collection::make($media)->mapWithKeys(function (Media $item) use ($resolved) {
            $disk = Storage::disk(Media::diskName());
            $candidates = array_filter([
                $this->normalize($item->filename),
                $this->normalize($item->url()),
                $this->normalize($item->thumbUrl()),
                ...array_map(fn (string $path) => $this->normalize($disk->url($path)), $item->storedPaths()),
            ]);

            $labels = [];
            foreach ($resolved as $label => $value) {
                if (in_array($value, $candidates, true)) {
                    $labels[] = $label;
                }
            }

            return [$item->id => $labels];
        })->all();
    }

    /** @return array<string, callable(): mixed> */
    protected function definitions(): array
    {
        return [
            'Фавикон' => fn () => Setting::value('general', 'favicon'),
            'OG-изображение' => fn () => Setting::value('seo', 'og_image'),
        ];
    }

    /**
     * Media filenames (the filename column) referenced by any definition. A
     * reference may be a disk URL, a root-relative path or the filename itself;
     * links to a thumbnail or a responsive copy count for the original.
     *
     * @return list<string>
     */
    public function referencedFilenames(): array
    {
        $prefix = $this->normalize(Storage::disk(Media::diskName())->url('x'));
        $prefix = $prefix !== null ? substr($prefix, 0, -1) : '';
        $filenames = [];

        foreach ($this->definitions() as $value) {
            $path = $this->normalize($value());
            if ($path === null) {
                continue;
            }

            foreach ([$path, $prefix !== '' && str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : null] as $candidate) {
                if ($candidate !== null && $candidate !== '') {
                    $filenames[] = preg_replace('/\.(thumb|w\d+)\.(webp|avif)$/', '.$2', $candidate) ?? $candidate;
                }
            }
        }

        return array_values(array_unique($filenames));
    }

    private function normalize(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $path = parse_url($value, PHP_URL_PATH);

        return ltrim(is_string($path) ? $path : $value, '/');
    }
}
