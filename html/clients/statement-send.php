<?php
declare(strict_types=1);
/**
 * Action `statement_send` (log `client.statement_send`: from, to, lines, recipients — a count, never an address —, closing balance and currency; confirm; `external_send` for agents): billing.write. The client's statement over a period
 * (`ct_client_statement()`: invoices, credit notes, payments and write-offs with the running balance) is queued, one outbox row per recipient — the contacts who receive invoices, else the client's email, else `to_email` —, for the worker
 * (slice 8) to send. A statement carries no secure link: a link is minted at a send and would kill the ones already in the client's hands.
 */
require_once dirname(__DIR__, 2) . '/app/features/clients/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('client') ?? request_integer('client_id') ?? request_integer('id') ?? refuse(422, 'Say which client.');
$c = find_client($pdo, $id) ?? refuse(404, 'Client not found.');
$errors = [];
$from = req_date('from', 'The start', $errors);
$to = req_date('to', 'The end', $errors);
$toEmail = req_has('to_email') ? (string) req_val('to_email') : null;
$message = req_has('message') ? (string) req_val('message') : null;
if ($message !== null && mb_strlen($message) > 2000) {
    $errors['message'] = 'The message is at most 2,000 characters.';
}
if ($from !== null && $to !== null && $to < $from) {
    $errors['to'] = 'The end is on or after the start.';
}
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$out = ct_guard($pdo, static function () use ($pdo, $me, $c, $from, $to, $toEmail, $message): array {
    $pdo->beginTransaction();
    $rows = client_statement($pdo, $c['client_id'], $from, $to);
    if ($rows === []) {
        throw new DomainException('There is nothing on the statement for that period.');
    }
    $recipients = invoice_recipients($pdo, $c['client_id'], $toEmail);
    if ($recipients === []) {
        throw new DomainException('Nobody to send it to: the client has no contact who receives invoices and no email — add one, or type an address.');
    }
    $firm = (string) (one_value($pdo, 'SELECT firm_name FROM ct_settings WHERE id = 1') ?: 'Consultant Tracking');
    $lines = ['Hello,', ''];
    if ($message !== null && $message !== '') {
        $lines[] = $message;
        $lines[] = '';
    }
    $lines[] = 'Here is the statement of account for ' . ($c['legal_name'] ?: $c['name']) . ($from !== null || $to !== null ? ' (' . ($from !== null ? format_date($from) : 'the beginning') . ' to ' . format_date($to ?? date('Y-m-d')) . ')' : '') . '.';
    $lines[] = '';
    $closing = [];
    foreach ($rows as $r) {
        $lines[] = format_date($r['entry_date']) . '  ' . str_pad($r['description'], 46) . '  ' . ((float) $r['charge'] > 0 ? fmt_money($r['charge'], $r['currency']) : '') . ((float) $r['credit'] > 0 ? '  -' . fmt_money($r['credit'], $r['currency']) : '') . '  balance ' . fmt_money($r['running_balance'], $r['currency']);
        $closing[$r['currency']] = $r['running_balance'];
    }
    $lines[] = '';
    foreach ($closing as $cur => $bal) {
        $lines[] = 'Balance owed in ' . $cur . ': ' . fmt_money($bal, (string) $cur);
    }
    $lines[] = '';
    $lines[] = 'Please reply to this email with any question.';
    $lines[] = '';
    $lines[] = 'Thank you,';
    $lines[] = $firm;
    $text = implode("\n", $lines) . "\n";
    $html = '<pre style="font-family:inherit;white-space:pre-wrap">' . e($text) . '</pre>';
    $queued = queue_client_mail($pdo, 'client', $c['client_id'], $c['client_id'], 'statement', $recipients, 'Statement of account — ' . $firm, $text, $html);
    $cur = count($closing) === 1 ? (string) array_key_first($closing) : null;
    log_activity($pdo, 'client.statement_send', 'client', $c['client_id'], ['client_id' => $c['client_id'], 'after' => ['from' => $from, 'to' => $to, 'lines' => count($rows), 'recipients' => count($recipients), 'queued' => $queued,
        'amount' => $cur === null ? null : (string) $closing[$cur], 'currency' => $cur]]);
    $pdo->commit();
    return ['recipients' => count($recipients), 'queued' => $queued, 'lines' => count($rows)];
});
ct_done('Queued the statement for ' . $out['recipients'] . ' recipient' . ($out['recipients'] === 1 ? '' : 's'), $c['client_id'], ct_land(return_path('/clients/' . $c['client_id'] . '/statement'), 'sent'), 'invoiceChanged',
    ['client_id' => $c['client_id'], 'recipients' => $out['recipients'], 'queued' => $out['queued'], 'lines' => $out['lines']]);
