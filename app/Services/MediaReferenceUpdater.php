<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Setting;
use App\Support\MediaReferenceRegistry;
use App\Support\MediaUsageIndex;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites links to a media file after its file changed (replacement or crop
 * write a new file under the same record, so the URL changes).
 *
 * Every text field and setting from MediaReferenceRegistry is updated with
 * its matching algorithm: the original goes to the new original, the
 * thumbnail to the new thumbnail, a responsive copy to the new copy of the
 * same width (or the original).
 *
 * Text rows are saved through their registered model, so model events and
 * LogsActivity see the change; settings changes are logged as
 * settings_updated. Everything runs in one transaction with the caller's
 * record change.
 */
class MediaReferenceUpdater
{
    public function __construct(private readonly MediaUsageIndex $index) {}

    /**
     * @param  Media  $old  Detached model with the previous filename and variants
     * @return int How many text rows and settings were changed
     */
    public function rewrite(Media $old, Media $new): int
    {
        if ($old->filename === $new->filename) {
            return 0;
        }

        $map = $this->pathMap($old, $new);
        $needles = MediaReferenceRegistry::needles($old->filename);

        return DB::transaction(function () use ($map, $needles) {
            $changed = 0;

            foreach (MediaReferenceRegistry::TEXT_FIELDS as $field) {
                $columns = $field['columns'];
                $rows = DB::table($field['table'])
                    ->where(function ($query) use ($columns, $needles) {
                        foreach ($columns as $column) {
                            foreach ($needles as $needle) {
                                $query->orWhere($column, 'like', '%'.$needle.'%');
                            }
                        }
                    })
                    ->get(array_values(array_unique([$field['key'], ...$columns])));

                foreach ($rows as $row) {
                    $updates = [];
                    foreach ($columns as $column) {
                        $value = $row->{$column};
                        if (is_string($value) && ($next = MediaReferenceRegistry::replace($value, $map)) !== $value) {
                            $updates[$column] = $next;
                        }
                    }

                    if ($updates === []) {
                        continue;
                    }

                    $changed++;
                    $model = $field['model']::query()->where($field['key'], $row->{$field['key']})->first();
                    $model !== null
                        ? $model->update($updates)
                        : DB::table($field['table'])->where($field['key'], $row->{$field['key']})->update($updates);
                }
            }

            $changed += $this->rewriteSettings($map);

            // Rows may change without model events: the text index is stale.
            $this->index->forget();
            DB::afterCommit(fn () => $this->index->forget());

            return $changed;
        });
    }

    /** @param array<string, string> $map */
    private function rewriteSettings(array $map): int
    {
        $before = Setting::grouped();
        $changes = [];

        foreach (MediaReferenceRegistry::SETTINGS as [$group, $key]) {
            $value = $before[$group][$key] ?? null;
            if (is_string($value) && $value !== '' && ($next = MediaReferenceRegistry::replace($value, $map)) !== $value) {
                Setting::set($group, $key, $next);
                $changes["{$group}.{$key}"] = [$value, $next];
            }
        }

        if ($changes !== []) {
            ActivityLog::record(null, 'settings_updated', $changes);
            // Another request may re-cache the old values before the commit.
            DB::afterCommit(fn () => Setting::flushCache());
        }

        return count($changes);
    }

    /**
     * Old stored path => new stored path, for the original, the thumbnail and
     * every responsive copy.
     *
     * @return array<string, string>
     */
    private function pathMap(Media $old, Media $new): array
    {
        $map = [$old->filename => $new->filename];

        $oldThumb = ImageOptimizer::thumbPath($old->filename);
        if ($oldThumb !== $old->filename) {
            $newThumb = ImageOptimizer::thumbPath($new->filename);
            $map[$oldThumb] = $new->has_thumb && $newThumb !== $new->filename ? $newThumb : $new->filename;
        }

        $newVariants = array_map('intval', $new->variants ?? []);
        foreach ($old->variants ?? [] as $width) {
            $width = (int) $width;
            $map[ImageOptimizer::variantPath($old->filename, $width)] = in_array($width, $newVariants, true)
                ? ImageOptimizer::variantPath($new->filename, $width)
                : $new->filename;
        }

        return $map;
    }
}
