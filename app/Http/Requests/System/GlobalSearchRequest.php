<?php

namespace App\Http\Requests\System;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Global search request (Cmd+K). Open to every signed-in user: GlobalSearch
 * itself searches only entities the user may view.
 */
class GlobalSearchRequest extends FormRequest
{
    /** Maximum query length: enough for any title and not too much for LIKE. */
    public const MAX_LENGTH = 100;

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }

    public function term(): string
    {
        return trim((string) $this->validated('q'));
    }
}
