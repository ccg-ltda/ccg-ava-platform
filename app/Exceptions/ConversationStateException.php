<?php

namespace App\Exceptions;

use RuntimeException;

/** An action on a conversation that its current state, or the user's role over it, does not allow. The message is for the user. */
class ConversationStateException extends RuntimeException {}
