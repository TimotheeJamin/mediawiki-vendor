<?php

namespace Wikimedia\Parsoid;

/**
 * Parsoid internal error that we don't know how to recover from.
 * Likely a result of bad configuration / bad code / edge case.
 */
class InternalException extends \Exception {
}
