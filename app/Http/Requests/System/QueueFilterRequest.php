<?php

namespace App\Http\Requests\System;

use App\Enums\QueueJobStatus;
use Illuminate\Foundation\Http\FormRequest;

/** Queue page filter: one job status and the page number. An unknown status shows every job. */
class QueueFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('queue.view') === true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'max:32'],
        ];
    }

    /** The chosen status, or null for all jobs. */
    public function status(): ?QueueJobStatus
    {
        return QueueJobStatus::tryFrom((string) $this->validated('status'));
    }

    /** List page number, at least 1. */
    public function page(): int
    {
        return max(1, $this->integer('page', 1));
    }
}
