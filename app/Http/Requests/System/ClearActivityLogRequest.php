<?php

namespace App\Http\Requests\System;

use App\Services\System\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/** Clearing the activity log: entries before a date (not in the future). */
class ClearActivityLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('activity-log.delete') === true;
    }

    public function rules(DisplayTime $time): array
    {
        return [
            'before' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$time->now()->toDateString()],
        ];
    }

    public function messages(): array
    {
        return [
            'before.required' => 'Укажите дату, до которой очистить журнал',
            'before.date_format' => 'Некорректная дата',
            'before.before_or_equal' => 'Дата не может быть в будущем',
        ];
    }

    /** Start of the chosen day in the display time zone as a UTC moment. */
    public function cutoff(DisplayTime $time): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('before'), $time->zone())->startOfDay()->utc();
    }
}
