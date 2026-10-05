<?php /** The rates tab (screen `engagement-rates`): one row per consulting role — the card's rate, this engagement's override editable in place; each staffed person's resolved rate today. Data: e, may (manage), here */
$id = $e['engagement_id']; $rr = $e['role_rates']; $cur = $e['currency'];
$source = ['member_override' => 'their override', 'engagement_role' => 'this engagement\'s role rate', 'rate_card' => 'the card', 'none' => 'nothing — no rate']; ?>
<div class="row g-3" id="engagement-rates-content">
    <div class="col-lg-7">
        <div class="card mb-3" id="engagement-rates-roles"><div class="card-header"><h5 class="card-title mb-0">Rates per consulting role (<?= e($cur) ?> an hour)</h5></div>
            <div class="fs-12 text-muted px-3 pt-2" id="engagement-rates-card">The card: <?= $rr['card_name'] === null ? 'none (set one on the engagement or the client)' : e($rr['card_name']) ?>. An override here beats the card; a person's override beats both.</div>
            <div class="table-responsive"><table class="table mb-0" id="engagement-rates-table"><thead><tr><th>Role</th><th class="text-end">Card</th><th class="text-end">This engagement</th><?php if ($may['manage']): ?><th></th><?php endif; ?></tr></thead><tbody>
                <?php foreach ($rr['roles'] as $x): $rid = $x['consulting_role_id']; ?>
                    <tr id="rate-row-<?= $rid ?>">
                        <td class="fw-semibold"><?= e($x['role_title']) ?></td>
                        <td class="text-end fs-12 text-muted" id="rate-row-<?= $rid ?>-card"><?= $x['card_rate'] === null ? '—' : e(fmt_money($x['card_rate'], $cur, false)) ?></td>
                        <?php if ($may['manage']): ?>
                        <td colspan="2"><form method="post" action="/engagements/rates/save.php" hx-post="/engagements/rates/save.php" hx-target="#flash" class="input-group input-group-sm" id="rate-row-<?= $rid ?>-form" style="max-width: 16rem; margin-left: auto">
                            <?= csrf_field() ?><input type="hidden" name="engagement" value="<?= $id ?>"><input type="hidden" name="role" value="<?= $rid ?>"><input type="hidden" name="return_to" value="<?= e('/engagements/' . $id . '/rates') ?>">
                            <input type="text" name="bill_rate" class="form-control text-end" inputmode="decimal" value="<?= e($x['engagement_rate'] === null ? '' : number_format((float) $x['engagement_rate'], 2, '.', '')) ?>" placeholder="the card's" id="rate-row-<?= $rid ?>-field" aria-label="Rate for <?= e($x['role_title']) ?>">
                            <button type="submit" class="btn btn-light" id="rate-row-<?= $rid ?>-save-btn">Save</button></form></td>
                        <?php else: ?><td class="text-end" id="rate-row-<?= $rid ?>-rate"><?= $x['engagement_rate'] === null ? '<span class="text-muted">the card\'s</span>' : e(fmt_money($x['engagement_rate'], $cur, false)) ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card" id="engagement-rates-people"><div class="card-header"><h5 class="card-title mb-0">What each person bills today</h5></div>
            <?php if ($rr['people'] === []): ?><div class="card-body text-muted" id="engagement-rates-people-empty">Nobody is staffed today.</div><?php else: ?>
            <div class="list-group list-group-flush"><?php foreach ($rr['people'] as $p): ?><div class="list-group-item d-flex justify-content-between fs-12" id="resolved-rate-<?= $p['member_id'] ?>"><span><?= e($p['member_name']) ?> <span class="text-muted">· <?= e($p['role_title']) ?></span></span><span class="fw-semibold"><?= $p['bill_rate'] === null ? '<span class="text-danger">no rate</span>' : e(fmt_money($p['bill_rate'], $cur)) . '/h' ?> <span class="text-muted fw-normal">from <?= e($source[$p['rate_source']] ?? $p['rate_source']) ?></span></span></div><?php endforeach; ?></div><?php endif; ?>
        </div>
    </div>
</div>
