<?php

namespace App\Services\Media;

/**
 * The single registry of fields that may reference a media file.
 *
 * Usage labels (MediaUsage, MediaPlaces), the deletion guard, the
 * "used / unused" filter, the text usage index (MediaUsageIndex, invalidated
 * by MediaTextObserver) and link rewriting after a file change
 * (MediaReferenceUpdater) all read these lists: a new media-bearing field is
 * registered here once. How a link looks inside a text is decided by MediaUrls.
 */
final class MediaReferenceRegistry
{
    /** Settings holding a file URL or HTML with file links: [group, key, label]. */
    public const SETTINGS = [
        ['general', 'favicon', 'Фавикон'],
        ['seo', 'og_image', 'OG-изображение'],
    ];

    /**
     * Foreign keys to media.id. owner — the record the editor opens (a short
     * type name, see MediaPlaces::owners()/place()), owner_key — the column
     * holding its id. Example:
     *
     *     ['table' => 'articles', 'column' => 'cover_media_id', 'owner' => 'article', 'owner_key' => 'id', 'label' => 'Обложка статьи'],
     *
     * @var list<array{table: string, column: string, owner: string, owner_key: string, label: string}>
     */
    public const FOREIGN_KEYS = [];

    /**
     * Text columns rendered as HTML or as a link. key — the row key used when
     * rewriting; model — whose saved/deleted events invalidate the usage index
     * (MediaTextObserver); rewrites also save through it, so LogsActivity
     * records them. Example:
     *
     *     [
     *         'table' => 'articles', 'columns' => ['body_html'], 'key' => 'id',
     *         'owner' => 'article', 'owner_key' => 'id', 'label' => 'Текст статьи', 'model' => Article::class,
     *     ],
     *
     * @var list<array{table: string, columns: list<string>, key: string, owner: string, owner_key: string, label: string, model: class-string}>
     */
    public const TEXT_FIELDS = [];
}
