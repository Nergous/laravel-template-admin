<?php

namespace App\Observers;

use App\Services\Media\MediaUsageIndex;
use Illuminate\Database\DatabaseManager;

/**
 * Clears the index of media links in texts (MediaUsageIndex) when a model with
 * such texts is saved or deleted. Attached to the models from
 * MediaReferenceRegistry::TEXT_FIELDS in AppServiceProvider.
 *
 * The index is cleared at once and again after the commit: a request that
 * rebuilds it mid-transaction would otherwise cache the old rows.
 */
class MediaTextObserver
{
    public function __construct(
        private readonly MediaUsageIndex $index,
        private readonly DatabaseManager $db,
    ) {}

    public function saved(): void
    {
        $this->forget();
    }

    public function deleted(): void
    {
        $this->forget();
    }

    private function forget(): void
    {
        $this->index->forget();
        $this->db->afterCommit(fn () => $this->index->forget());
    }
}
