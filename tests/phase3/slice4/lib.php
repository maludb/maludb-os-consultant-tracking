<?php
/**
 * Helpers for the slice 4 proofs (docs/build-specs/expenses-receipts.md, "Proof"). Builds on slice 3's (so slice 2's and 1's). The world is slice 3's: Acme T&M (ENG) with Priya, Marco (its manager) and Dana staffed,
 * a Beta fixed bid with Priya, the Standard card; the settings' markup is set to 10 % here (the policy). The cast: the owner (1), Priya (26), Marco (27, the manager), Bea (28, Billing), Ann (29, Finance), Dana (30).
 */
require dirname(__DIR__) . '/slice3/lib.php';

/** A real JPEG the camera field would send (GD draws it; finfo says image/jpeg). */
function jpeg_file(string $name = 'receipt.jpg'): string
{
    $p = sys_get_temp_dir() . '/ct-' . bin2hex(random_bytes(4)) . '-' . $name;
    $im = imagecreatetruecolor(48, 64);
    imagefill($im, 0, 0, imagecolorallocate($im, 250, 250, 240));
    imagestring($im, 2, 4, 4, 'SMOKE', imagecolorallocate($im, 20, 20, 20));
    imagejpeg($im, $p, 80);
    return $p;
}
/** How many files lie under storage/attachments/expense/ (an orphan check). */
function receipt_files(): int
{
    clearstatcache();
    $n = 0;
    $root = dirname(__DIR__, 3) . '/storage/attachments/expense';
    if (!is_dir($root)) { return 0; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) { if ($f->isFile()) { $n++; } }
    return $n;
}
/** The slice-4 world: slice 3's, with the policy markup of 10 % (D7's default is 0; the proof sets the settings' value). */
function expense_world(): array
{
    [$acme, $beta] = time_world();
    q('UPDATE ct_settings SET default_markup_pct = 10 WHERE id = 1');
    return [$acme, $beta];
}
/** Record an expense over HTTP as a person: [status, body]. */
function record_expense(string $jar, int $engagement, string $amount, array $extra = []): array
{
    return act($jar, '/expenses/save.php', array_merge(['engagement' => $engagement, 'date' => date('Y-m-d'), 'category' => category('meals'), 'amount' => $amount, 'description' => 'SMOKE expense'], $extra));
}
function category(string $key): int { return (int) one('SELECT id FROM expense_categories WHERE key = :k', ['k' => $key]); }
function expense_row(int $id): array { return q('SELECT * FROM expenses WHERE id = :id', ['id' => $id])[0] ?? []; }
