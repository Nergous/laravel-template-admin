<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Services\System\Settings;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds the places that reference media files. Every place comes from
 * MediaReferenceRegistry:
 *  - settings (favicon, OG image and the like), matched by URL;
 *  - foreign keys, queried directly (indexed, batched per request);
 *  - text links, taken from MediaUsageIndex.
 *
 * A reference is ['label' => shown in the library, 'owner' => setting or the
 * owner type from the registry, 'id' => owner id or null]. MediaPlaces turns
 * references into places with an edit link.
 */
class MediaUsage
{
    /** Ids per whereIn query. */
    private const CHUNK = 1000;

    public function __construct(
        private readonly Settings $settings,
        private readonly MediaUsageIndex $index,
        private readonly MediaUrls $urls,
    ) {}

    /**
     * Labels of the places that reference each file (the library cards, drawer and poll).
     *
     * @param  iterable<int, Media>  $media
     * @return array<int, list<string>>
     */
    public function for(iterable $media): array
    {
        return array_map(
            fn (array $references) => array_values(array_unique(array_column($references, 'label'))),
            $this->references($media),
        );
    }

    /**
     * Every reference to each file. $fresh skips the cached index: the deletion
     * guard must not act on a stale one.
     *
     * @param  iterable<int, Media>  $media
     * @return array<int, list<array{label: string, owner: string, id: int|null}>> media id => references
     */
    public function references(iterable $media, bool $fresh = false): array
    {
        $media = Collection::make($media);
        $bySetting = $this->settingReferences();
        $references = [];

        foreach ($media as $item) {
            $references[$item->id] = $bySetting[$item->filename] ?? [];
        }

        if ($references === []) {
            return $references;
        }

        foreach (MediaReferenceRegistry::FOREIGN_KEYS as $key) {
            foreach (array_chunk(array_keys($references), self::CHUNK) as $ids) {
                $rows = DB::table($key['table'])->whereIn($key['column'], $ids)->get([$key['column'], $key['owner_key']]);
                foreach ($rows as $row) {
                    $references[(int) $row->{$key['column']}][] = [
                        'label' => $key['label'],
                        'owner' => $key['owner'],
                        'id' => (int) $row->{$key['owner_key']},
                    ];
                }
            }
        }

        if (MediaReferenceRegistry::TEXT_FIELDS !== []) {
            $index = $fresh ? $this->index->fresh() : $this->index->map();
            foreach ($media as $item) {
                foreach ($index[$item->filename] ?? [] as [$owner, $id, $label]) {
                    $references[$item->id][] = ['label' => $label, 'owner' => $owner, 'id' => $id];
                }
            }
        }

        return $references;
    }

    /**
     * Narrows a media query to referenced files ($used) or to the rest: the
     * "used / unused" filter of the library.
     */
    public function constrain(Builder $query, bool $used): Builder
    {
        $clause = fn ($q) => $this->usedClause($q);

        return $used ? $query->where($clause) : $query->whereNot($clause);
    }

    /** Where-clause matching referenced media rows (columns qualified with the media table). */
    private function usedClause(Builder $query): void
    {
        $query->whereIn('media.filename', $this->linkedFilenames());

        foreach (MediaReferenceRegistry::FOREIGN_KEYS as $key) {
            $query->orWhereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from($key['table'])
                ->whereColumn($key['table'].'.'.$key['column'], 'media.id'));
        }
    }

    /**
     * Filenames linked by URL from settings and registered text fields.
     *
     * @return list<string>
     */
    private function linkedFilenames(): array
    {
        $text = MediaReferenceRegistry::TEXT_FIELDS !== [] ? array_keys($this->index->map()) : [];

        return array_values(array_unique([
            ...array_map('strval', array_keys($this->settingReferences())),
            ...array_map('strval', $text),
        ]));
    }

    /**
     * Filename => references from the registered settings.
     *
     * @return array<string, list<array{label: string, owner: string, id: null}>>
     */
    private function settingReferences(): array
    {
        $found = [];

        foreach (MediaReferenceRegistry::SETTINGS as [$group, $key, $label]) {
            foreach ($this->urls->filenames($this->settings->value($group, $key)) as $filename) {
                $found[$filename][] = ['label' => $label, 'owner' => 'setting', 'id' => null];
            }
        }

        return $found;
    }
}
