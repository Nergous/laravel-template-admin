<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/** Media file kind (the media.type column); detected from the MIME type on upload. */
enum MediaType: string
{
    use HasLabel;

    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Other = 'other';

    /** Kind by MIME type (e.g. image/png, video/mp4); unknown — Other. */
    public static function fromMime(?string $mime): self
    {
        $mime = (string) $mime;

        return match (true) {
            str_starts_with($mime, 'image/') => self::Image,
            str_starts_with($mime, 'video/') => self::Video,
            str_starts_with($mime, 'audio/') => self::Audio,
            str_starts_with($mime, 'text/') => self::Document,
            in_array($mime, [
                'application/pdf',
                'application/msword',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ], true) => self::Document,
            default => self::Other,
        };
    }

    /**
     * File extensions of this kind that can be uploaded to the library
     * (UploadRules); Other has no uploadable formats.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::Image => ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'],
            self::Video => ['mp4', 'webm', 'mov'],
            self::Audio => ['mp3', 'wav', 'ogg'],
            self::Document => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'],
            self::Other => [],
        };
    }
}
