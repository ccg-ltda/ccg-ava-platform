<?php

namespace App\Integrations;

use RuntimeException;

/** The URL of an integration cannot be called safely from the server (the message is shown to the user). */
class UnsafeTarget extends RuntimeException {}
