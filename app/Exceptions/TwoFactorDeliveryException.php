<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a two-factor code was generated and cached successfully but the
 * delivery email could not be sent.
 *
 * This is deliberately distinct from a generic failure: the code IS valid and
 * IS in the cache, so the caller can tell the user to retry delivery rather
 * than restart the login. Callers must return 503, not 500 — the request was
 * well-formed and the user did nothing wrong.
 */
class TwoFactorDeliveryException extends Exception
{
}
