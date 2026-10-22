<?php

/*
 * Technical limits of the reminders of the Catálogo (scheduled emails to the person who wrote them). None of them is a commercial rule.
 */
return [
    // Attempts per reminder (the first send plus the retries of a failure that happened before the message could have left).
    'max_attempts' => 3,

    // Minutes to wait before the 2nd and the 3rd attempt.
    'backoff_minutes' => [5, 30],

    // A reminder queued or being sent for longer than this lost its worker: what was only queued goes back to waiting; what was sending is `uncertain` (never resent).
    'stuck_minutes' => 15,

    // A reminder that is found due for longer than this (the scheduler or the queue were down) is NOT sent: an «alistar pedido» that arrives days late misleads.
    'grace_hours' => 24,

    // How far ahead it can be scheduled and how long its text may be.
    'max_days_ahead' => 366,
    'subject_max' => 160,
    'body_max' => 5000,
];
