<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A media library folder. The name is the full path ("Banners/2026", see MediaFolderPath);
 * files reference the folder by it (media.folder), and the row keeps the folder
 * while it is empty. Folders are created by MediaFolderService.
 *
 * @property int $id
 * @property string $name Full path
 * @property int|null $created_by
 */
class MediaFolder extends Model
{
    protected $fillable = ['name', 'created_by'];
}
