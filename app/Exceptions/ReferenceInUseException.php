<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * A record cannot be deleted while other records still reference it (e.g. a
 * category that still has posts); the message says what holds it. The user
 * gets it as a flash error on the page they came from. An expected outcome, so
 * it is not logged.
 */
class ReferenceInUseException extends RuntimeException implements ShouldntReport
{
    public function render(): RedirectResponse
    {
        return back()->with('error', $this->getMessage());
    }
}
