<?php
// client/billing.php - balances and payment history
require_once __DIR__ . '/client_layout.php';
$c = client_boot();
$pdo = $c['pdo'];
$unpaid = $payments = [];
$totals = ['charged' => 0.0, 'paid' => 0.0];
if (!$c['dbError']) {
    $unpaid = client_unpaid($pdo, $c['ownerId']);
    $payments = qall($pdo,
        "SELECT pay.payment_id, pay.amount_paid, pay.payment_date, pay.payment_method, pay.payment_status, pay.void_reason, pay.appointment_id, p.pet_name
         FROM Payments pay JOIN Appointments a ON a.appointment_id = pay.appointment_id JOIN Pets p ON p.pet_id = a.pet_id
         WHERE p.owner_id = ? ORDER BY pay.payment_date DESC, pay.payment_id DESC LIMIT 100", [$c['ownerId']]);
    $t = qall($pdo,
        "SELECT COALESCE(SUM(c.total_charged),0) AS charged FROM Appointments a JOIN Pets p ON p.pet_id = a.pet_id
         JOIN (SELECT appointment_id, SUM(charged_price) AS total_charged FROM AppointmentServices GROUP BY appointment_id) c ON c.appointment_id = a.appointment_id
         WHERE p.owner_id = ? AND a.status = 'Complete'", [$c['ownerId']]);
    $totals['charged'] = (float) ($t[0]['charged'] ?? 0);
    $paid = qall($pdo,
        "SELECT COALESCE(SUM(pay.amount_paid),0) AS total_paid
         FROM Payments pay JOIN Appointments a ON a.appointment_id = pay.appointment_id
         JOIN Pets p ON p.pet_id = a.pet_id WHERE p.owner_id = ? AND pay.payment_status = 'Recorded'", [$c['ownerId']]);
    $totals['paid'] = (float) ($paid[0]['total_paid'] ?? 0);
}
$balance = 0.0;
foreach ($unpaid as $u) {
    $balance += (float) $u['total_charged'] - (float) $u['total_paid'];
}
client_header($c, 'Billing', 'Charges, payments and balances', 'billing');
?>
        <section class="stat-grid" aria-label="Billing summary">
          <article class="stat-card" style="--accent:#4164c9"><span class="stat-label">Total billed</span><strong class="stat-value">₱<?= number_format($totals['charged'], 2) ?></strong><span class="stat-note">Completed visits</span></article>
          <article class="stat-card" style="--accent:#008b78"><span class="stat-label">Total paid</span><strong class="stat-value">₱<?= number_format($totals['paid'], 2) ?></strong><span class="stat-note"><?= count($payments) ?> payment<?= count($payments) === 1 ? '' : 's' ?> in history</span></article>
          <article class="stat-card" style="--accent:#c4453d"><span class="stat-label">Balance due</span><strong class="stat-value<?= $balance > 0 ? ' due' : '' ?>">₱<?= number_format($balance, 2) ?></strong><span class="stat-note"><?= $balance > 0 ? 'Pay at the front desk' : 'All paid up' ?></span></article>
        </section>

        <section class="panel appointment-panel">
          <div class="panel-header"><h2>Unpaid balances</h2><span class="data-count"><?= count($unpaid) ?> visit<?= count($unpaid) === 1 ? '' : 's' ?></span></div>
          <div class="panel-body appointment-panel-body">
            <?php client_search('balance-search', 'Find an unpaid visit', 'Search pet, service or date…', 'unpaid-body', 'balance-count', 'balance-empty'); ?>
            <div class="table-wrap appointment-table-wrap data-table-card">
              <table class="records-table appointment-table billing-balance-table">
                <colgroup><col style="width:16%"><col style="width:14%"><col style="width:22%"><col style="width:16%"><col style="width:16%"><col style="width:16%"></colgroup>
                <thead><tr><th>Visit</th><th>Pet</th><th>Service</th><th class="col-right">Total</th><th class="col-right">Paid</th><th class="col-right">Balance</th></tr></thead>
                <tbody id="unpaid-body">
                  <?php if ($unpaid): ?>
                    <?php foreach ($unpaid as $i => $u): ?>
                      <tr data-search-item<?= $i % 2 ? ' class="alt"' : '' ?>><td data-label="Visit"><?= e(date('M j, Y', strtotime($u['appointment_date']))) ?></td><td data-label="Pet"><?= e($u['pet_name']) ?></td><td data-label="Service"><?= e($u['service_names']) ?></td>
                        <td class="col-right" data-label="Total">₱<?= number_format((float) $u['total_charged'], 2) ?></td><td class="col-right col-money" data-label="Paid">₱<?= number_format((float) $u['total_paid'], 2) ?></td>
                        <td class="col-right col-due" data-label="Balance">₱<?= number_format((float) $u['total_charged'] - (float) $u['total_paid'], 2) ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="empty-row" id="balance-empty" data-search-empty hidden><td colspan="6"><div class="empty-state-box"><strong>No visits match your search</strong><small>Try another pet, service or date.</small></div></td></tr>
                  <?php else: ?>
                    <tr class="appointment-empty-row"><td colspan="6"><div class="appointment-empty-state"><strong>You’re all paid up</strong><span>New balances appear here after a completed visit.</span></div></td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>

        <section class="panel appointment-panel">
          <div class="panel-header"><h2>Payment history</h2><span class="data-count"><?= count($payments) ?> payment<?= count($payments) === 1 ? '' : 's' ?></span></div>
          <div class="panel-body appointment-panel-body">
            <?php client_search('payment-search', 'Find a payment', 'Search pet, payment method or date…', 'payment-body', 'payment-count', 'payment-empty'); ?>
            <div class="table-wrap appointment-table-wrap data-table-card">
              <table class="records-table appointment-table billing-payment-table">
                <colgroup><col style="width:12%"><col style="width:20%"><col style="width:16%"><col style="width:16%"><col style="width:16%"><col style="width:20%"></colgroup>
                <thead><tr><th>Payment</th><th>Date</th><th>Pet</th><th class="col-center">Method</th><th class="col-right">Amount</th><th>Status</th></tr></thead>
                <tbody id="payment-body">
                  <?php if ($payments): ?>
                    <?php foreach ($payments as $i => $p): ?>
                      <tr data-search-item<?= $i % 2 ? ' class="alt"' : '' ?>><td data-label="Payment">#TRX-<?= (int) $p['payment_id'] ?></td><td data-label="Date"><?= e(date('M j, Y g:i A', strtotime($p['payment_date']))) ?></td><td data-label="Pet"><?= e($p['pet_name']) ?></td>
                        <td class="col-center" data-label="Method"><?= e($p['payment_method'] ?: '—') ?></td><td class="col-right col-money" data-label="Amount">₱<?= number_format((float) $p['amount_paid'], 2) ?></td>
                        <td data-label="Status"><span class="status <?= e(status_class($p['payment_status'])) ?>"><?= e($p['payment_status']) ?></span><?= $p['void_reason'] ? '<br><span class="hint">' . e($p['void_reason']) . '</span>' : '' ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="empty-row" id="payment-empty" data-search-empty hidden><td colspan="6"><div class="empty-state-box"><strong>No payments match your search</strong><small>Try another pet, method or date.</small></div></td></tr>
                  <?php else: ?>
                    <tr class="appointment-empty-row"><td colspan="6"><div class="appointment-empty-state"><strong>No payments recorded</strong><span>Payments made at the front desk will appear here.</span></div></td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>
<?php client_footer($c);