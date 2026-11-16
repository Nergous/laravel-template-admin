<?php

namespace App\Exceptions;

/**
 * A media file cannot be deleted while records still reference it; the
 * message names the places.
 */
class MediaInUseException extends ReferenceInUseException {}
