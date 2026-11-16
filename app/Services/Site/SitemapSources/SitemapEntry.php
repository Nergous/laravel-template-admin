<?php

namespace App\Services\Site\SitemapSources;

use App\Enums\SitemapChangeFrequency;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/** One /sitemap.xml entry: an address (absolute or root-relative) and optional hints for search engines. */
final class SitemapEntry
{
    /** @throws InvalidArgumentException If the priority is outside 0 to 1 */
    public function __construct(
        public readonly string $loc,
        public readonly ?CarbonInterface $lastmod = null,
        public readonly ?SitemapChangeFrequency $changefreq = null,
        public readonly ?float $priority = null,
    ) {
        // Written this way so that NaN fails the check too.
        if ($priority !== null && ! ($priority >= 0.0 && $priority <= 1.0)) {
            throw new InvalidArgumentException("Приоритет записи sitemap должен быть от 0 до 1, получено {$priority}.");
        }
    }

    /**
     * Entry tags for the template; a root-relative address is prefixed with $base.
     *
     * @return array{loc: string, lastmod: ?string, changefreq: ?string, priority: ?string}
     */
    public function toArray(string $base): array
    {
        return [
            'loc' => str_starts_with($this->loc, '/') && ! str_starts_with($this->loc, '//') ? $base.$this->loc : $this->loc,
            'lastmod' => $this->lastmod?->toAtomString(),
            'changefreq' => $this->changefreq?->value,
            'priority' => $this->priority !== null ? number_format($this->priority, 1, '.', '') : null,
        ];
    }
}
