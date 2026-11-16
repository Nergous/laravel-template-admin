<?php

namespace App\Http\Resources\System;

use App\Models\ActivityLog;
use App\Services\System\ActivityLogLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An activity log entry on the log page, the dashboard and the user page
 * (AuditLog in resources/js/admin/types.ts). Expects user loaded.
 *
 * @mixin ActivityLog
 */
class ActivityLogResource extends JsonResource
{
    /** @param  string|null  $subjectUrl  The subject's admin page (ActivitySubjectLinks), if it exists and the viewer may open it */
    public function __construct(
        ActivityLog $log,
        private readonly ActivityLogLabels $labels,
        private readonly ?string $subjectUrl = null,
    ) {
        parent::__construct($log);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $subjectType = $this->labels->subjectType($this->resource);

        return [
            'id' => $this->id,
            'action' => $this->action,
            'action_label' => $this->labels->action($this->resource),
            // Current author name → actor_label snapshot (survives force deletion) → "Система".
            'actor' => $this->labels->actor($this->resource),
            'subject' => $this->subject_label ?: $subjectType,
            'subject_type' => $subjectType,
            'subject_url' => $this->subjectUrl,
            'changes_count' => is_array($this->changes) ? count($this->changes) : 0,
            'changes' => $this->changes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
