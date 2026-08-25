<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a terminal credential operation is refused because of the
 * terminal's current lifecycle state (e.g. regenerating a revoked terminal,
 * or reactivating a terminal that is not revoked).
 *
 * Controllers translate this to HTTP 409 Conflict.
 */
class TerminalStateConflictException extends RuntimeException {}
