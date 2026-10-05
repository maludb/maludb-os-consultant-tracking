<?php
declare(strict_types=1);
/**
 * The ONE money formatter (docs/build-specs/sso-shell.md): an amount with two decimals and thousands separators, NEGATIVE IN
 * PARENTHESES, the currency after it — "1,608.24 USD", "(100.00) USD". A figure never renders without its currency. Zero is "0.00 USD".
 * fmt_money_cell() is the table cell: right-aligned, muted when zero, `text-danger` when negative.
 */
function fmt_money(string|int|float|null $amount, string $currency, bool $withCurrency = true): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    $n = is_string($amount) ? (float) str_replace(',', '', $amount) : (float) $amount;
    $text = number_format(abs($n), 2, '.', ',');
    if (round($n, 2) < 0) {
        $text = '(' . $text . ')';
    }
    return $withCurrency ? $text . ' ' . strtoupper(substr($currency, 0, 3)) : $text;
}

function fmt_money_cell(string|int|float|null $amount, string $currency, string $id = ''): string
{
    $n = $amount === null || $amount === '' ? 0.0 : (float) str_replace(',', '', (string) $amount);
    $class = 'text-end text-nowrap' . (round($n, 2) == 0 ? ' text-muted' : (round($n, 2) < 0 ? ' text-danger' : ''));
    return '<td class="' . $class . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>' . e(fmt_money($amount, $currency)) . '</td>';
}

/** A decimal typed by a person — "1,608.24", "1608.24", "(100.00)", "-100" — as a canonical "1608.24" / "-100.00" string, or null when it is not a number. */
function parse_money(?string $raw): ?string
{
    if ($raw === null) {
        return null;
    }
    $s = trim($raw);
    if ($s === '') {
        return null;
    }
    $neg = false;
    if (preg_match('/^\((.*)\)$/', $s, $m)) {
        $neg = true;
        $s = $m[1];
    }
    $s = str_replace([',', ' ', '$', '€', '£'], '', $s);
    if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) {
        return null;
    }
    $v = (float) $s;
    if ($neg) {
        $v = -abs($v);
    }
    return number_format($v, 2, '.', '');
}

/** "12.3 KB", "4.0 MB". */
function fmt_bytes(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : ($bytes >= 1024 ? number_format($bytes / 1024, 1) . ' KB' : $bytes . ' B');
}

/** An hours figure: "7.5 h", "0.25 h". */
function fmt_hours(string|int|float|null $hours): string
{
    return $hours === null || $hours === '' ? '—' : rtrim(rtrim(number_format((float) $hours, 2, '.', ','), '0'), '.') . ' h';
}

/** The firm's base currency (the settings'), once per request. */
function ct_base_currency(): string
{
    static $c = null;
    return $c ??= ((string) one_value(db(), 'SELECT base_currency FROM ct_settings WHERE id = 1') ?: 'USD');
}
