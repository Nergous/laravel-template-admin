<?php

namespace App\Models;

use App\Support\MediaFolderPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * A media library folder. The name is the full path ("Баннеры/2026", see
 * MediaFolderPath); files reference their folder by it (media.folder), and the
 * row keeps the folder alive while it is empty.
 *
 * @property int $id
 * @property string $name Full path
 * @property int|null $created_by
 */
class MediaFolder extends Model
{
    protected $fillable = ['name', 'created_by'];

    /** Makes sure a folder (and every folder above it) exists. */
    public static function register(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        foreach (MediaFolderPath::lineage($path) as $folder) {
            static::firstOrCreate(['name' => $folder], ['created_by' => Auth::id()]);
        }
    }
}
