<?php

namespace App\Enums;

/** Format ImageOptimizer encodes library images to. */
enum ImageFormat: string
{
    case Webp = 'webp';
    case Avif = 'avif';

    public function extension(): string
    {
        return $this->value;
    }

    public function mime(): string
    {
        return match ($this) {
            self::Webp => 'image/webp',
            self::Avif => 'image/avif',
        };
    }

    /** Format by file extension; null — not an encoded file (stored unprocessed). */
    public static function fromPath(string $path): ?self
    {
        return self::tryFrom(strtolower(pathinfo($path, PATHINFO_EXTENSION)));
    }
}
