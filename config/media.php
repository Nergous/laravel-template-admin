<?php

return [
    /*
     * Filesystem disk for media library files (originals and thumbnails under
     * media/). Any disk from config/filesystems.php; remote disks (s3) are
     * supported — processing works through streams and local temp copies.
     * Uploads are always staged on the local disk (storage/app/private/temp)
     * before the UploadMedia job moves them here.
     */
    'disk' => env('MEDIA_DISK', 'public'),

    /*
     * Output format of processed images: webp, or avif when GD was built with
     * AVIF support (falls back to webp otherwise). Uploads in either format
     * are accepted regardless of this value.
     */
    'image_format' => env('MEDIA_IMAGE_FORMAT', 'webp'),

    /*
     * Widths of the responsive copies generated next to each processed image
     * for srcset (see Media::srcset()). Only widths above the 600 px thumbnail
     * and below the processed original are produced.
     */
    'variant_widths' => [960, 1440],
];
