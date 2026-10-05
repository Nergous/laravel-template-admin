<?php

namespace App\Support;

use App\Models\Media;
use App\Models\Setting;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds the places that reference media files. Every place comes from
 * MediaReferenceRegistry and is matched with its one algorithm: settings by
 * URL, foreign keys directly (indexed, batched per request), text links
 * through MediaUsageIndex.
 *
 * A reference is ['label' => shown in the library, 'owner' => setting or the
 * owner type from the registry, 'id' => owner id or null]. A place is a
 * reference resolved for the editor: ['label', 'title', 'url' to edit it].
 * Settings resolve here; a project that registers its own owners overrides
 * owners() and place() to give them a title and an edit link.
 */
class MediaUsage
{
    /** Ids per whereIn query. */
    private const CHUNK = 1000;

    public function __construct(private readonly MediaUsageIndex $index) {}

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
     * Every reference to each file. $fresh skips cached indexes: the deletion
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
     * Places that reference one file, with a link to edit each (the media drawer).
     *
     * @return list<array{label: string, title: ?string, url: ?string}>
     */
    public function places(Media $media, bool $fresh = false): array
    {
        return $this->placesFor([$media], $fresh)[$media->id] ?? [];
    }

    /**
     * @param  iterable<int, Media>  $media
     * @return array<int, list<array{label: string, title: ?string, url: ?string}>>
     */
    public function placesFor(iterable $media, bool $fresh = false): array
    {
        return $this->resolve($this->references($media, $fresh));
    }

    /**
     * Turns references (see references()) into places; owners deleted in the
     * meantime are left out, repeats are merged.
     *
     * @param  array<int, list<array{label: string, owner: string, id: int|null}>>  $references
     * @return array<int, list<array{label: string, title: ?string, url: ?string}>>
     */
    public function resolve(array $references): array
    {
        $owners = $this->owners(array_merge([], ...array_values($references)));

        return array_map(function (array $list) use ($owners) {
            $places = [];
            foreach ($list as $reference) {
                $place = $this->place($reference, $owners);
                if ($place !== null) {
                    $places[$place['label']."\0".$place['title']."\0".$place['url']] = $place;
                }
            }

            return array_values($places);
        }, $references);
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
    protected function usedClause(Builder $query): void
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
    protected function linkedFilenames(): array
    {
        $text = MediaReferenceRegistry::TEXT_FIELDS !== [] ? array_keys($this->index->map()) : [];

        return array_values(array_unique(array_map('strval', [
            ...array_keys($this->settingReferences()),
            ...$text,
        ])));
    }

    /**
     * Owner records of the references, keyed by owner type and id. Override to
     * load the owners registered in MediaReferenceRegistry, e.g.
     * ['article' => Article::query()->whereKey($ids)->get(['id', 'title'])->keyBy('id')->all()].
     *
     * @param  list<array{label: string, owner: string, id: int|null}>  $references
     * @return array<string, array<int, mixed>>
     */
    protected function owners(array $references): array
    {
        return [];
    }

    /**
     * A place for one reference. Settings link to the settings page; other
     * owners keep their label without a link until a subclass resolves them.
     *
     * @param  array{label: string, owner: string, id: int|null}  $reference
     * @param  array<string, array<int, mixed>>  $owners
     * @return array{label: string, title: ?string, url: ?string}|null
     */
    protected function place(array $reference, array $owners): ?array
    {
        return $reference['owner'] === 'setting'
            ? ['label' => $reference['label'], 'title' => null, 'url' => route('admin.settings.index')]
            : ['label' => $reference['label'], 'title' => null, 'url' => null];
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
            foreach (MediaReferenceRegistry::filenames(Setting::value($group, $key)) as $filename) {
                $found[$filename][] = ['label' => $label, 'owner' => 'setting', 'id' => null];
            }
        }

        return $found;
    }
}
