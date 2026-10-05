<?php
declare(strict_types=1);

/**
 * The words of the outbox (slice 8): which kinds go to a CLIENT (email only, the secure link in the body — never a note, a cost or another client's name) and which to a PERSON (an in-app row, an email, optionally a text),
 * and how long a text may be. A kind here is only a label; the queueing functions of slices 3–6 and notify() write the rows, outbox_send_batch() sends them.
 */

const OUTBOX_CLIENT_KINDS = ['invoice', 'reminder', 'statement'];
const OUTBOX_MAX_ATTEMPTS = 5;                    // the fifth failed attempt is final
const OUTBOX_TEXT_MAX = 460;                      // the kernel's K6 takes 480 and prefixes the application's name

/** A short label for a kind (the log and the settings screen use it; never the body). */
function outbox_kind_label(string $kind): string
{
    $client = ['invoice' => 'An invoice', 'reminder' => 'A reminder', 'statement' => 'A statement'];
    return $client[$kind] ?? (defined('NOTICE_KINDS') ? (NOTICE_KINDS[$kind] ?? null) : null) ?? str_replace('_', ' ', $kind);
}
