<?php
declare(strict_types=1);

/**
 * What an outbox row becomes when it is sent (slice 8). A CLIENT's mail is the row as slice 6 wrote it — the words of `writing-to-clients` and the secure link at its foot, verbatim in the text and the html. A PERSON's mail is the
 * notice's title as the subject and its body, with one line to the application at the foot; a text is the title and the body in one line. Nothing here adds a cost, a rate, a note or another client's name: the row holds what it holds.
 */

/** [subject, text, html] of an email row. */
function outbox_email_parts(array $row): array
{
    $subject = (string) ($row['subject'] ?? '') !== '' ? (string) $row['subject'] : outbox_kind_label((string) $row['kind']);
    $text = (string) $row['body'];
    if ($row['client_id'] !== null) {
        $html = ($row['body_html'] ?? '') !== '' ? (string) $row['body_html'] : '<pre style="font-family:inherit;white-space:pre-wrap">' . htmlspecialchars($text, ENT_QUOTES) . '</pre>';
        return [$subject, $text, $html];
    }
    $link = rtrim((string) env('APP_URL', ''), '/') . '/notifications';
    $text .= "\n\n" . $link . "\n";
    $paras = array_filter(array_map('trim', preg_split('/\R{2,}/', (string) $row['body']) ?: []), static fn (string $p): bool => $p !== '');
    $html = '';
    foreach ($paras as $p) {
        $html .= '<p>' . nl2br(htmlspecialchars($p, ENT_QUOTES)) . '</p>';
    }
    $html .= '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">' . htmlspecialchars($link, ENT_QUOTES) . '</a></p>';
    return [$subject, $text, $html];
}

/** The words of a text: the title, then the body, in one line of at most OUTBOX_TEXT_MAX characters. */
function outbox_text_words(array $row): string
{
    $words = trim(preg_replace('/\s+/', ' ', trim((string) ($row['subject'] ?? '')) . '. ' . trim((string) $row['body'])) ?? '');
    return mb_substr($words === '.' ? outbox_kind_label((string) $row['kind']) : $words, 0, OUTBOX_TEXT_MAX);
}
