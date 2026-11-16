<?php

namespace App\Observers;

use App\Models\Media;
use App\Services\Media\MediaFolderService;

/**
 * Safety net: a file put into a folder by name registers that folder (see
 * MediaFolder) even when the record was saved bypassing the services. The
 * services register the folder themselves on behalf of the actor; here it is created without an author.
 */
class MediaObserver
{
    public function __construct(private readonly MediaFolderService $folders) {}

    public function saving(Media $media): void
    {
        if ($media->isDirty('folder')) {
            $this->folders->register($media->folder);
        }
    }
}
