<?php

namespace App\Rules;

use App\Support\Media\PixelLimit;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Limits the pixel resolution of images; other files pass.
 *
 * The size limit (max) does not protect against decompression bombs (see PixelLimit),
 * so such files are rejected at the input and never reach the queue.
 */
final class ImageWithinPixelLimit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $mime = $value->getMimeType();
        if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
            return;
        }

        if (PixelLimit::exceeds($value->getPathname())) {
            $fail('Слишком большое разрешение изображения (макс. '.PixelLimit::megapixels().' Мп)');
        }
    }
}
