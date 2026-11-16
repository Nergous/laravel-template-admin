<?php

namespace App\Services\Media;

use App\Models\Media;
use Illuminate\Contracts\Routing\UrlGenerator;

/**
 * Places that use a file, shaped for the editor: the label, the owner record's
 * title and a link to edit it. MediaUsage finds the references; owners deleted
 * in the meantime are left out, repeats are merged.
 *
 * A place is ['label' => label, 'title' => owner title or null,
 * 'url' => edit link or null]. Settings resolve here; a project that registers
 * its own owners in MediaReferenceRegistry overrides owners() and place() to
 * give them a title and an edit link.
 */
class MediaPlaces
{
    public function __construct(
        private readonly MediaUsage $usage,
        protected readonly UrlGenerator $urls,
    ) {}

    /**
     * Places of one file (the media library drawer).
     *
     * @return list<array{label: string, title: ?string, url: ?string}>
     */
    public function for(Media $media, bool $fresh = false): array
    {
        return $this->resolve($this->usage->references([$media], $fresh))[$media->id] ?? [];
    }

    /**
     * Turns references (MediaUsage::references()) into places.
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
     * owners keep their label without a link until a subclass resolves them
     * (return null to drop a reference whose owner is gone).
     *
     * @param  array{label: string, owner: string, id: int|null}  $reference
     * @param  array<string, array<int, mixed>>  $owners
     * @return array{label: string, title: ?string, url: ?string}|null
     */
    protected function place(array $reference, array $owners): ?array
    {
        return $reference['owner'] === 'setting'
            ? ['label' => $reference['label'], 'title' => null, 'url' => $this->urls->route('admin.settings.index')]
            : ['label' => $reference['label'], 'title' => null, 'url' => null];
    }
}
