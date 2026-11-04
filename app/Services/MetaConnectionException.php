<?php

namespace App\Services;

use RuntimeException;

/** A Meta connection problem with a message that is safe to show: never Meta's text, a code or a token. */
class MetaConnectionException extends RuntimeException {}
