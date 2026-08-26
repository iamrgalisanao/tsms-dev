<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a credential may not be issued to a terminal because it is not
 * currently eligible (revoked or inactive). Distinct from
 * TerminalStateConflictException: this is a refusal (HTTP 403), not a
 * lifecycle-ordering conflict (HTTP 409).
 */
class TerminalNotEligibleException extends RuntimeException {}
