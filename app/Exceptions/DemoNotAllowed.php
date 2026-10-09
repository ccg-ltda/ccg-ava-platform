<?php

namespace App\Exceptions;

use RuntimeException;

/** The demo tools were asked for where they must not run (production, or a Workspace that is not the administrative one). */
class DemoNotAllowed extends RuntimeException {}
