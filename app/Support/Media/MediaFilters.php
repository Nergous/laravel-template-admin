<?php

namespace App\Support\Media;

use App\Enums\MediaType;
use App\Enums\MediaUsageFilter;

/**
 * Media list filters shared by the list itself and by bulk actions on
 * "all matching files" (the same flat names: search, type, folder,
 * usage). Built from validated data; an empty value means no filter.
 */
final class MediaFilters
{
    public function __construct(
        public readonly string $search = '',
        public readonly ?MediaType $type = null,
        public readonly ?string $folder = null,
        public readonly ?MediaUsageFilter $usage = null,
    ) {}

    /** @param  array<string, mixed>  $input  Validated data */
    public static function fromInput(array $input): self
    {
        $value = fn (string $key) => is_string($input[$key] ?? null) && $input[$key] !== '' ? $input[$key] : null;

        return new self(
            trim((string) ($input['search'] ?? '')),
            MediaType::tryFrom((string) $value('type')),
            $value('folder'),
            MediaUsageFilter::tryFrom((string) $value('usage')),
        );
    }

    /**
     * Search and the usage filter at the root search the whole library;
     * otherwise a level shows only its own files.
     */
    public function wholeLibrary(): bool
    {
        return $this->folder === null && ($this->search !== '' || $this->usage !== null);
    }

    /**
     * For page props: an empty string where there is no filter.
     *
     * @return array{search: string, type: string, folder: string, usage: string}
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'type' => $this->type?->value ?? '',
            'folder' => $this->folder ?? '',
            'usage' => $this->usage?->value ?? '',
        ];
    }
}
