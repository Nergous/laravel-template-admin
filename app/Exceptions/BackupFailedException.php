<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A backup error whose message is written for people and contains no paths,
 * hosts or tool output: it can be shown in the UI. Anything else (process,
 * driver, filesystem errors) the UI replaces with a generic message, and the
 * details go to the application log.
 */
class BackupFailedException extends RuntimeException {}
