<?php
/**
 * A subprocess for the shares proof: calls one share function the way the records server will — php share_call.php <function> <kernel|person> <member id or -> '<json list of the leading arguments>' — and prints one JSON line:
 * {"doc": …} | {"refused": "<sentence>"} | {"error": …}, plus "context_after": what app.member_id reads after the call (the kernel's elevation must be put back).
 */
require dirname(__DIR__, 3) . '/app/bootstrap.php';
require dirname(__DIR__, 3) . '/app/features/exports/queries.php';
[, $fn, $caller, $member, $args] = $argv + [null, null, null, '-', '[]'];
$pdo = db();
if ($member !== '-') {
    $pdo->prepare('SELECT set_config(?, ?, false)')->execute(['app.member_id', $member]);
}
$before = (string) $pdo->query("SELECT COALESCE(current_setting('app.member_id', true), '')")->fetchColumn();
try {
    $out = ['doc' => $fn($pdo, ...json_decode((string) $args, true), caller: $caller)];
} catch (ShareRefused $e) {
    $out = ['refused' => $e->getMessage()];
} catch (Throwable $e) {
    $out = ['error' => get_class($e) . ': ' . $e->getMessage()];
}
$out['context_before'] = $before;
$out['context_after'] = (string) $pdo->query("SELECT COALESCE(current_setting('app.member_id', true), '')")->fetchColumn();
echo json_encode($out) . "\n";
