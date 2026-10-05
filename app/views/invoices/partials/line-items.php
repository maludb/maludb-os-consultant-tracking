<?php /** The backing items of a line, expandable. Data: l (a line with items), i (invoice), editable (a draft's editor: each item removable), here */
$lid = (int) $l['line_id']; $cur = $i['currency']; $editable = $editable ?? false;
if ($l['items'] === []): return; endif; ?>
<details class="fs-12" id="line-items-<?= $lid ?>"><summary class="text-muted" id="line-items-<?= $lid ?>-toggle"><?= (int) $l['item_count'] ?> backing item<?= (int) $l['item_count'] === 1 ? '' : 's' ?></summary>
    <div class="table-responsive"><table class="table table-sm mb-0 fs-12"><tbody>
    <?php foreach ($l['items'] as $x): $xid = (int) $x['item_id']; ?>
        <tr id="item-<?= $xid ?>">
            <?php if ($x['time_entry_id'] !== null): ?><td class="text-nowrap"><?= e(format_date($x['t_date'])) ?></td><td><?= e($x['t_member']) ?> · <?= e(fmt_hours($x['t_hours'])) ?><?= $x['t_note'] ? ' — ' . e($x['t_note']) : '' ?></td>
            <?php elseif ($x['expense_id'] !== null): ?><td class="text-nowrap"><?= e(format_date($x['e_date'])) ?></td><td><?= hx_link(with_back('/expenses/' . (int) $x['expense_id'], $here), e((string) $x['e_number'])) ?> · <?= e((string) $x['e_description']) ?> <?php if ($x['e_receipt_id'] !== null): ?><a href="/attachments/<?= (int) $x['e_receipt_id'] ?>" target="_blank" rel="noopener" class="badge bg-soft-success text-success" id="item-<?= $xid ?>-receipt"><i class="feather-paperclip me-1"></i>Receipt</a><?php endif; ?></td>
            <?php elseif ($x['usage_charge_id'] !== null): ?><td class="text-nowrap"><?= e(format_date($x['u_end'])) ?></td><td><?= hx_link(with_back('/usage/' . (int) $x['usage_charge_id'], $here), e((string) $x['u_number'])) ?> · <?= e((string) ($x['u_provider'] ?? 'Hosting')) ?> — <?= e((string) $x['u_description']) ?></td>
            <?php else: ?><td class="text-nowrap"><?= $x['m_accepted'] ? e(format_date(substr((string) $x['m_accepted'], 0, 10))) : '' ?></td><td>Milestone <?= e((string) $x['m_name']) ?></td><?php endif; ?>
            <?= fmt_money_cell($x['amount'], $cur) ?>
            <?php if ($editable): ?><td class="text-end"><form method="post" action="/invoices/items/remove.php" hx-post="/invoices/items/remove.php" hx-target="#flash" hx-confirm="Take this item off the invoice? It becomes unbilled again."><?= csrf_field() ?><input type="hidden" name="item" value="<?= $xid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-sm btn-light" id="item-<?= $xid ?>-remove-btn">Take off</button></form></td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</details>
