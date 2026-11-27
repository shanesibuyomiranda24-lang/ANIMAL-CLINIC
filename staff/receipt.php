<?php
require_once __DIR__ . '/../includes/role_layout.php';
require_role(['staff']);

$paymentId = filter_var($_GET['payment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$receipt = null;
if ($paymentId !== false && $paymentId !== null) {
    $statement = db()->prepare(
        'SELECT r.receipt_no, r.issued_at, issued.full_name AS issued_by,
                pay.payment_id, pay.amount_paid, pay.payment_method, pay.payment_date,
                pay.payment_status, pay.verification_status, pay.transaction_reference, pay.void_reason,
                i.invoice_no, a.appointment_date, p.pet_name, o.full_name AS owner_name,
                (SELECT GROUP_CONCAT(s.service_name ORDER BY s.service_name SEPARATOR ", ")
                 FROM AppointmentServices aps
                 JOIN Services s ON s.service_id = aps.service_id
                 WHERE aps.appointment_id = a.appointment_id) AS service_names
         FROM Receipts r
         JOIN Payments pay ON pay.payment_id = r.payment_id
         LEFT JOIN Users issued ON issued.user_id = r.issued_by_user_id
         LEFT JOIN Invoices i ON i.invoice_id = pay.invoice_id
         LEFT JOIN Appointments a ON a.appointment_id = pay.appointment_id
         LEFT JOIN Pets p ON p.pet_id = a.pet_id
         LEFT JOIN PetOwners o ON o.owner_id = p.owner_id
         WHERE pay.payment_id = ?'
    );
    $statement->execute([(int) $paymentId]);
    $receipt = $statement->fetch() ?: null;
}

if (!$receipt) {
    http_response_code(404);
}

role_render('staff', ['billing'], 'Payment Receipt', 'Receipt details', function (array $context) use ($receipt): void {
    if (!$receipt) {
        echo '<section class="panel"><div class="empty"><strong>Receipt not found</strong><span>This payment does not have a printable receipt.</span></div></section>';
        return;
    }

    $voided = $receipt['payment_status'] !== 'Recorded';
    ?>
    <section class="panel receipt-panel">
      <div class="panel-header"><h2>Payment receipt</h2><span class="data-count"><?= e($receipt['receipt_no']) ?></span></div>
      <div class="panel-body">
        <div class="receipt-card">
          <div class="receipt-brand">
            <div><p class="receipt-eyebrow">Animal Clinic</p><h2>Payment Receipt</h2></div>
            <div class="receipt-meta"><strong><?= e($receipt['receipt_no']) ?></strong><span>Issued <?= e(date('M j, Y g:i A', strtotime($receipt['issued_at']))) ?></span></div>
          </div>
          <?php if ($voided): ?>
            <p class="receipt-void">Payment <?= e(strtolower($receipt['payment_status'])) ?><?= $receipt['void_reason'] ? ': ' . e($receipt['void_reason']) : '' ?></p>
          <?php endif; ?>
          <dl class="receipt-details">
            <div><dt>Client</dt><dd><?= e($receipt['owner_name'] ?: '—') ?></dd></div>
            <div><dt>Pet</dt><dd><?= e($receipt['pet_name'] ?: '—') ?></dd></div>
            <div><dt>Services</dt><dd><?= e($receipt['service_names'] ?: '—') ?></dd></div>
            <div><dt>Invoice</dt><dd><?= e($receipt['invoice_no'] ?: '—') ?></dd></div>
            <div><dt>Appointment</dt><dd><?= $receipt['appointment_date'] ? e(date('M j, Y g:i A', strtotime($receipt['appointment_date']))) : '—' ?></dd></div>
            <div><dt>Payment date</dt><dd><?= e(date('M j, Y g:i A', strtotime($receipt['payment_date']))) ?></dd></div>
            <div><dt>Payment method</dt><dd><?= e($receipt['payment_method']) ?></dd></div>
            <div><dt>Transaction reference</dt><dd><?= e($receipt['transaction_reference'] ?: '—') ?></dd></div>
            <div><dt>Verification</dt><dd><?= e($receipt['verification_status']) ?></dd></div>
            <div><dt>Received by</dt><dd><?= e($receipt['issued_by'] ?: 'Clinic staff') ?></dd></div>
          </dl>
          <div class="receipt-total"><span>Amount received</span><strong>₱<?= number_format((float) $receipt['amount_paid'], 2) ?></strong></div>
          <p class="receipt-thanks">Thank you for choosing Animal Clinic.</p>
        </div>
        <div class="receipt-actions">
          <a class="button secondary" href="billing.php">Back to billing</a>
          <button class="button" type="button" onclick="window.print()">Print receipt</button>
        </div>
      </div>
    </section>
    <style>
      .receipt-card{max-width:760px;margin:0 auto;padding:28px;background:#fff;border:1px solid #d7e8f5;border-radius:12px}
      .receipt-brand{padding-bottom:18px;display:flex;justify-content:space-between;gap:18px;border-bottom:2px solid #cfe5f5}
      .receipt-eyebrow{margin:0 0 3px;color:#015b8c;font-size:.75rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase}
      .receipt-brand h2{margin:0;color:#002e4a;font-size:1.55rem}
      .receipt-meta{display:grid;align-content:center;justify-items:end;gap:3px;color:#42637e;font-size:.82rem}
      .receipt-meta strong{color:#002e4a;font-size:1rem}
      .receipt-details{margin:18px 0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 20px}
      .receipt-details div{min-width:0}.receipt-details dt{color:#42637e;font-size:.74rem;font-weight:700;text-transform:uppercase}
      .receipt-details dd{margin:3px 0 0;color:#092947;font-size:.9rem;overflow-wrap:anywhere}
      .receipt-total{padding:16px 0;display:flex;justify-content:space-between;gap:12px;border-top:1px solid #d7e8f5}
      .receipt-total span{color:#42637e;font-weight:700}.receipt-total strong{color:#002e4a;font-size:1.35rem}
      .receipt-thanks{margin:14px 0 0;color:#42637e;text-align:center}
      .receipt-void{margin:14px 0 0;padding:9px;color:#842626;background:#fdeaea;border-radius:7px;font-weight:700}
      .receipt-actions{margin-top:16px;display:flex;justify-content:flex-end;gap:9px}
      @media(max-width:600px){.receipt-card{padding:18px}.receipt-brand{flex-direction:column}.receipt-meta{justify-items:start}.receipt-details{grid-template-columns:1fr}}
      @media print{
        body.role-app{height:auto;overflow:visible;background:#fff}
        .app-shell{height:auto;display:block;overflow:visible}
        .sidebar,.topbar,.receipt-actions{display:none!important}
        .main-area{display:block;overflow:visible}
        .page-content{width:100%;max-width:none;padding:0;overflow:visible}
        .panel{border:0;box-shadow:none}.panel-header{color:#002e4a;background:#fff;border-bottom:1px solid #d7e8f5}
        .receipt-card{max-width:none;border:0}
      }
    </style>
    <?php
});