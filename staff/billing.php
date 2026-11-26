<?php
require_once __DIR__ . '/../includes/role_layout.php';
require_role(['staff']);

$pdo = db();
$error = '';
$success = '';
$receiptId = 0;

if (isset($_SESSION['staff_billing_flash']) && is_array($_SESSION['staff_billing_flash'])) {
    $flash = $_SESSION['staff_billing_flash'];
    $success = (string) ($flash['message'] ?? '');
    $receiptId = (int) ($flash['receipt_id'] ?? 0);
    unset($_SESSION['staff_billing_flash']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Reload the page and try again.';
    } else {
        $action = posted_string('action');
        try {
            if ($action === 'generate_invoice') {
                $appointmentId = filter_var(posted_string('appointment_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($appointmentId === false) {
                    throw new DomainException('Choose a valid completed appointment.');
                }

                $appointmentStatement = $pdo->prepare(
                    'SELECT a.status, p.owner_id
                     FROM Appointments a
                     JOIN Pets p ON p.pet_id = a.pet_id
                     WHERE a.appointment_id = ?'
                );
                $appointmentStatement->execute([(int) $appointmentId]);
                $appointment = $appointmentStatement->fetch();
                if (!$appointment || $appointment['status'] !== 'Complete') {
                    throw new DomainException('Invoices can only be generated for completed appointments.');
                }

                $statement = $pdo->prepare('CALL sp_generate_invoice(?, ?)');
                $statement->execute([(int) $appointmentId, (int) $_SESSION['user_id']]);
                $invoiceId = (int) $statement->fetchColumn();
                $statement->closeCursor();
                if ($invoiceId < 1) {
                    throw new RuntimeException('Invoice generation returned no invoice identifier.');
                }

                role_notify_owner(
                    $pdo,
                    (int) $appointment['owner_id'],
                    'billing',
                    'Invoice available',
                    'An invoice is available for your completed appointment #' . (int) $appointmentId . '.',
                    'client/billing.php',
                    'invoice_generated_' . $invoiceId
                );
                role_notify_role(
                    $pdo,
                    'admin',
                    'billing',
                    'Invoice generated',
                    'An invoice was generated for appointment #' . (int) $appointmentId . '.',
                    'admin/admin_reports.php',
                    'invoice_generated_admin_' . $invoiceId
                );
                $_SESSION['staff_billing_flash'] = ['message' => 'Invoice generated successfully.'];
                header('Location: billing.php');
                exit;
            }

            if ($action === 'record_payment') {
                $invoiceId = filter_var(posted_string('invoice_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $amountInput = trim(posted_string('amount'));
                $method = posted_string('payment_method');
                $reference = trim(posted_string('transaction_reference'));
                $methods = ['Cash', 'Card', 'GCash', 'Maya', 'MariBank', 'Bank Transfer', 'Other'];

                if ($invoiceId === false) {
                    throw new DomainException('Choose a valid invoice.');
                }
                if (!preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/', $amountInput)
                    || (float) $amountInput <= 0) {
                    throw new DomainException('Enter a payment amount greater than zero, with no more than two decimal places.');
                }
                if (!in_array($method, $methods, true)) {
                    throw new DomainException('Choose a valid payment method.');
                }
                if (mb_strlen($reference) > 100) {
                    throw new DomainException('The transaction reference must be 100 characters or fewer.');
                }

                $ownerStatement = $pdo->prepare(
                    'SELECT p.owner_id
                     FROM Invoices i
                     JOIN Appointments a ON a.appointment_id = i.appointment_id
                     JOIN Pets p ON p.pet_id = a.pet_id
                     WHERE i.invoice_id = ?'
                );
                $ownerStatement->execute([(int) $invoiceId]);
                $ownerId = (int) $ownerStatement->fetchColumn();
                if ($ownerId < 1) {
                    throw new DomainException('That invoice is no longer available.');
                }

                $statement = $pdo->prepare('CALL sp_record_payment(?, ?, ?, ?, ?)');
                $statement->execute([
                    (int) $invoiceId,
                    number_format((float) $amountInput, 2, '.', ''),
                    $method,
                    (int) $_SESSION['user_id'],
                    $reference !== '' ? $reference : null,
                ]);
                $receiptId = (int) $statement->fetchColumn();
                $statement->closeCursor();
                if ($receiptId < 1) {
                    throw new RuntimeException('Payment recording returned no payment identifier.');
                }
                role_notify_role(
                    $pdo,
                    'staff',
                    'billing',
                    'Payment recorded',
                    'Payment was recorded for invoice #' . (int) $invoiceId . '.',
                    'staff/billing.php',
                    'payment_recorded_staff_' . $receiptId
                );

                $_SESSION['staff_billing_flash'] = [
                    'message' => 'Payment recorded successfully.',
                    'receipt_id' => $receiptId,
                ];
                header('Location: billing.php');
                exit;
            }

            if ($action === 'void_payment') {
                $paymentId = filter_var(posted_string('payment_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $reason = trim(posted_string('reason'));
                if ($paymentId === false) {
                    throw new DomainException('Choose a valid payment to correct.');
                }
                if ($reason === '' || mb_strlen($reason) > 255) {
                    throw new DomainException('Enter a correction reason of 1 to 255 characters.');
                }

                $paymentStatement = $pdo->prepare(
                    'SELECT pay.payment_status, i.invoice_id
                     FROM Payments pay
                     JOIN Invoices i ON i.invoice_id = pay.invoice_id
                     WHERE pay.payment_id = ?'
                );
                $paymentStatement->execute([(int) $paymentId]);
                $payment = $paymentStatement->fetch();
                if (!$payment || $payment['payment_status'] !== 'Recorded') {
                    throw new DomainException('Only recorded payments can be corrected.');
                }

                $statement = $pdo->prepare('CALL sp_void_payment(?, ?, ?)');
                $statement->execute([(int) $paymentId, $reason, (int) $_SESSION['user_id']]);
                $statement->closeCursor();
                role_notify_role(
                    $pdo,
                    'staff',
                    'billing',
                    'Payment corrected',
                    'Payment #' . (int) $paymentId . ' was voided. Reason: ' . $reason,
                    'staff/billing.php',
                    'payment_voided_staff_' . (int) $paymentId
                );
                $_SESSION['staff_billing_flash'] = [
                    'message' => 'Payment voided. The invoice balance has been recalculated; record a replacement payment if needed.',
                ];
                header('Location: billing.php');
                exit;
            }

            throw new DomainException('Unsupported billing request.');
        } catch (DomainException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Staff billing action: ' . $exception->getMessage());
            if ($action === 'record_payment') {
                $error = 'The payment could not be recorded. Check the remaining balance and try again.';
            } elseif ($action === 'void_payment') {
                $error = 'The payment could not be corrected. Refresh and verify its current status before trying again.';
            } else {
                $error = 'The invoice could not be generated. Please try again.';
            }
        }
    }
}

$invoices = $pdo->query('SELECT * FROM v_invoices ORDER BY appointment_date DESC, invoice_id DESC')->fetchAll();
$unbilledAppointments = $pdo->query(
    "SELECT a.appointment_id, a.appointment_date, p.pet_name, o.full_name AS owner_name,
            COALESCE(s.service_names, '') AS service_names
     FROM Appointments a
     JOIN Pets p ON p.pet_id = a.pet_id
     JOIN PetOwners o ON o.owner_id = p.owner_id
     LEFT JOIN Invoices i ON i.appointment_id = a.appointment_id
     LEFT JOIN (
         SELECT aps.appointment_id, GROUP_CONCAT(s.service_name ORDER BY s.service_name SEPARATOR ', ') AS service_names
         FROM AppointmentServices aps
         JOIN Services s ON s.service_id = aps.service_id
         GROUP BY aps.appointment_id
     ) s ON s.appointment_id = a.appointment_id
     WHERE a.status = 'Complete' AND i.invoice_id IS NULL
     ORDER BY a.appointment_date DESC"
)->fetchAll();
$payments = $pdo->query(
    "SELECT pay.payment_id, pay.amount_paid, pay.payment_method, pay.payment_date,
            pay.payment_status, pay.transaction_reference, pay.void_reason, i.invoice_no, r.receipt_no,
            p.pet_name, o.full_name AS owner_name
     FROM Payments pay
     LEFT JOIN Invoices i ON i.invoice_id = pay.invoice_id
     LEFT JOIN Receipts r ON r.payment_id = pay.payment_id
     LEFT JOIN Appointments a ON a.appointment_id = pay.appointment_id
     LEFT JOIN Pets p ON p.pet_id = a.pet_id
     LEFT JOIN PetOwners o ON o.owner_id = p.owner_id
     ORDER BY pay.payment_date DESC, pay.payment_id DESC
     LIMIT 100"
)->fetchAll();

role_render('staff', ['billing'], 'Billing', 'Generate invoices, record payments, manage corrections and print receipts', function (array $context) use ($error, $success, $receiptId, $invoices, $unbilledAppointments, $payments): void {
    if ($error !== '') {
        echo '<div class="notice error" role="alert">' . e($error) . '</div>';
    }
    if ($success !== '') {
        echo '<div class="notice success" role="status">' . e($success);
        if ($receiptId > 0) {
            echo ' <a href="receipt.php?payment_id=' . $receiptId . '">Print receipt</a>';
        }
        echo '</div>';
    }
    ?>
    <section class="panel" id="unbilled-visits">
      <div class="panel-header"><h2>Completed visits awaiting invoices</h2><span class="data-count"><?= count($unbilledAppointments) ?> visits</span></div>
      <?php if ($unbilledAppointments): ?>
        <div class="panel-body"><label class="field" style="max-width:420px">Search completed visits<input id="unbilled-search" type="search" placeholder="Search pet, owner or service"></label></div>
        <div class="table-wrap data-table-card">
          <table class="records-table">
            <colgroup><col style="width:16%"><col style="width:28%"><col style="width:31%"><col></colgroup>
            <thead><tr><th>Date</th><th>Pet / Owner</th><th>Services</th><th>Action</th></tr></thead>
            <tbody>
              <?php foreach ($unbilledAppointments as $appointment): ?>
                <?php $searchText = strtolower($appointment['pet_name'] . ' ' . $appointment['owner_name'] . ' ' . $appointment['service_names']); ?>
                <tr data-unbilled-row data-search="<?= e($searchText) ?>">
                  <td data-label="Date"><?= e(date('M j, Y g:i A', strtotime($appointment['appointment_date']))) ?></td>
                  <td data-label="Pet / Owner"><?= e($appointment['pet_name']) ?><br><span class="hint"><?= e($appointment['owner_name']) ?></span></td>
                  <td data-label="Services"><?= e($appointment['service_names'] !== '' ? $appointment['service_names'] : '—') ?></td>
                  <td data-label="Action">
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="generate_invoice">
                      <input type="hidden" name="appointment_id" value="<?= (int) $appointment['appointment_id'] ?>">
                      <button class="button small" type="submit">Generate invoice</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <tr class="empty-row" id="unbilled-empty" hidden><td colspan="4"><div class="empty"><strong>No visits match your search</strong></div></td></tr>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="table-wrap data-table-card">
          <table class="records-table">
            <colgroup><col style="width:16%"><col style="width:28%"><col style="width:31%"><col></colgroup>
            <thead><tr><th>Date</th><th>Pet / Owner</th><th>Services</th><th>Action</th></tr></thead>
            <tbody><tr class="empty-row"><td colspan="4"><div class="empty"><strong>No completed visits need an invoice</strong></div></td></tr></tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <div class="panel-header">
        <h2>Invoices &amp; Payments</h2>
        <div class="panel-actions"><span class="data-count"><?= count($invoices) ?> invoices</span><a class="button light small" href="#unbilled-visits">+ Add Invoice</a></div>
      </div>
      <?php if ($invoices): ?>
        <div class="panel-body">
          <div class="toolbar">
            <label class="field">Search invoices<input id="invoice-search" type="search" placeholder="Search invoice, pet or owner"></label>
            <label class="field">Payment status
              <select id="invoice-status-filter">
                <option value="">All statuses</option>
                <?php foreach (['Unpaid', 'Partially Paid', 'Paid', 'Void'] as $status): ?>
                  <option value="<?= e(strtolower($status)) ?>"><?= e($status) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
        </div>
        <div class="table-wrap data-table-card">
          <table class="records-table">
            <colgroup><col style="width:12%"><col style="width:13%"><col style="width:21%"><col style="width:11%"><col style="width:11%"><col style="width:11%"><col style="width:12%"><col></colgroup>
            <thead><tr><th>Invoice</th><th>Date</th><th>Client / Pet</th><th class="col-right">Total</th><th class="col-right">Paid</th><th class="col-right">Balance</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
              <?php foreach ($invoices as $index => $invoice): ?>
                <?php $searchText = strtolower($invoice['invoice_no'] . ' ' . $invoice['owner_name'] . ' ' . $invoice['pet_name']); ?>
                <tr id="invoice-<?= (int) $invoice['invoice_id'] ?>"<?= $index % 2 ? ' class="alt"' : '' ?> data-invoice-row data-search="<?= e($searchText) ?>" data-status="<?= e(strtolower($invoice['payment_status'])) ?>">
                  <td data-label="Invoice"><?= e($invoice['invoice_no']) ?></td>
                  <td data-label="Date"><?= e(date('M j, Y', strtotime($invoice['appointment_date']))) ?></td>
                  <td data-label="Client / Pet"><?= e($invoice['owner_name']) ?><br><span class="hint"><?= e($invoice['pet_name']) ?></span></td>
                  <td data-label="Total" class="col-right">₱<?= number_format((float) $invoice['invoice_total'], 2) ?></td>
                  <td data-label="Paid" class="col-right">₱<?= number_format((float) $invoice['amount_paid'], 2) ?></td>
                  <td data-label="Balance" class="col-right">₱<?= number_format((float) $invoice['balance_due'], 2) ?></td>
                  <td data-label="Status"><span class="status <?= e(role_status_class($invoice['payment_status'])) ?>"><?= e($invoice['payment_status']) ?></span></td>
                  <td data-label="Action">
                    <?php if ((float) $invoice['balance_due'] > 0 && $invoice['payment_status'] !== 'Void'): ?>
                      <button class="button small payment-btn" type="button" data-id="<?= (int) $invoice['invoice_id'] ?>" data-balance="<?= number_format((float) $invoice['balance_due'], 2, '.', '') ?>">Record payment</button>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <tr class="empty-row" id="invoice-empty" hidden><td colspan="8"><div class="empty"><strong>No invoices match your filters</strong></div></td></tr>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="table-wrap data-table-card">
          <table class="records-table">
            <colgroup><col style="width:12%"><col style="width:13%"><col style="width:21%"><col style="width:11%"><col style="width:11%"><col style="width:11%"><col style="width:12%"><col></colgroup>
            <thead><tr><th>Invoice</th><th>Date</th><th>Client / Pet</th><th class="col-right">Total</th><th class="col-right">Paid</th><th class="col-right">Balance</th><th>Status</th><th>Action</th></tr></thead>
            <tbody><tr class="empty-row"><td colspan="8"><div class="empty"><strong>No invoices have been generated</strong></div></td></tr></tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <div class="panel-header"><h2>Payment history</h2><span class="data-count">Latest <?= count($payments) ?> payments</span></div>
      <?php if ($payments): ?>
        <div class="panel-body"><label class="field" style="max-width:420px">Search payments<input id="payment-search" type="search" placeholder="Search receipt, invoice, pet or method"></label></div>
        <div class="table-wrap data-table-card">
          <table class="records-table">
            <colgroup><col style="width:13%"><col style="width:16%"><col style="width:21%"><col style="width:12%"><col style="width:14%"><col style="width:10%"><col style="width:9%"><col></colgroup>
            <thead><tr><th>Receipt</th><th>Date</th><th>Client / Pet</th><th>Invoice</th><th>Method</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
              <?php foreach ($payments as $index => $payment): ?>
                <?php $searchText = strtolower(($payment['receipt_no'] ?? '') . ' ' . ($payment['invoice_no'] ?? '') . ' ' . ($payment['owner_name'] ?? '') . ' ' . ($payment['pet_name'] ?? '') . ' ' . $payment['payment_method'] . ' ' . ($payment['void_reason'] ?? '')); ?>
                <tr<?= $index % 2 ? ' class="alt"' : '' ?> data-payment-row data-search="<?= e($searchText) ?>">
                  <td data-label="Receipt"><?= e($payment['receipt_no'] ?: '—') ?></td>
                  <td data-label="Date"><?= e(date('M j, Y g:i A', strtotime($payment['payment_date']))) ?></td>
                  <td data-label="Client / Pet"><?= e($payment['owner_name'] ?: '—') ?><br><span class="hint"><?= e($payment['pet_name'] ?: '—') ?></span></td>
                  <td data-label="Invoice"><?= e($payment['invoice_no'] ?: '—') ?></td>
                  <td data-label="Method"><?= e($payment['payment_method']) ?><?= $payment['transaction_reference'] ? '<br><span class="hint">' . e($payment['transaction_reference']) . '</span>' : '' ?></td>
                  <td data-label="Amount" class="col-right">₱<?= number_format((float) $payment['amount_paid'], 2) ?></td>
                  <td data-label="Status"><span class="status <?= e(role_status_class($payment['payment_status'])) ?>"><?= e($payment['payment_status']) ?></span><?= $payment['void_reason'] ? '<br><span class="hint">' . e($payment['void_reason']) . '</span>' : '' ?></td>
                  <td data-label="Action">
                    <div class="actions">
                      <?php if ($payment['receipt_no']): ?>
                        <a class="button small secondary" href="receipt.php?payment_id=<?= (int) $payment['payment_id'] ?>"><?= $payment['payment_status'] === 'Recorded' ? 'View receipt' : 'View voided receipt' ?></a>
                      <?php endif; ?>
                      <?php if ($payment['payment_status'] === 'Recorded'): ?>
                        <button class="button small danger correction-btn" type="button" data-id="<?= (int) $payment['payment_id'] ?>" data-amount="<?= number_format((float) $payment['amount_paid'], 2) ?>" data-reference="<?= e($payment['receipt_no'] ?: ('Payment #' . (int) $payment['payment_id'])) ?>">Correct</button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <tr class="empty-row" id="payment-empty" hidden><td colspan="8"><div class="empty"><strong>No payments match your search</strong></div></td></tr>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="table-wrap data-table-card">
          <table class="records-table">
            <colgroup><col style="width:13%"><col style="width:16%"><col style="width:21%"><col style="width:12%"><col style="width:14%"><col style="width:10%"><col style="width:9%"><col></colgroup>
            <thead><tr><th>Receipt</th><th>Date</th><th>Client / Pet</th><th>Invoice</th><th>Method</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead>
            <tbody><tr class="empty-row"><td colspan="8"><div class="empty"><strong>No payments have been recorded</strong></div></td></tr></tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <dialog id="payment-dialog" aria-labelledby="payment-title">
      <div class="dialog-head">
        <h2 id="payment-title">Record payment</h2>
        <button class="dialog-close" type="button" aria-label="Close dialog">×</button>
      </div>
      <form class="dialog-form" id="payment-form" method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="record_payment">
        <input type="hidden" name="invoice_id" id="payment-invoice-id">
        <div class="form-grid">
          <label class="field">Amount<input name="amount" id="payment-amount" type="number" min="0.01" step="0.01" required></label>
          <label class="field">Payment method
            <select name="payment_method" required>
              <option value="">Choose a method</option>
              <option>Cash</option><option>Card</option><option>GCash</option>
              <option>Maya</option><option>MariBank</option><option>Bank Transfer</option><option>Other</option>
            </select>
          </label>
          <label class="field full">Transaction reference <span class="hint">Optional for cash; enter the provider reference for online payments.</span><input name="transaction_reference" maxlength="100"></label>
        </div>
        <div class="form-actions">
          <button class="button secondary payment-cancel" type="button">Cancel</button>
          <button class="button" type="submit">Save payment</button>
        </div>
      </form>
    </dialog>
    <dialog id="correction-dialog" aria-labelledby="correction-title">
      <div class="dialog-head">
        <h2 id="correction-title">Correct payment</h2>
        <button class="dialog-close" type="button" aria-label="Close dialog">×</button>
      </div>
      <form class="dialog-form" id="correction-form" method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="void_payment">
        <input type="hidden" name="payment_id" id="correction-payment-id">
        <p class="notice info">This records an audited void and recalculates the invoice balance. To correct the amount or method, record a replacement payment afterward.</p>
        <p class="hint" id="correction-payment-label"></p>
        <label class="field">Reason for correction<textarea name="reason" maxlength="255" required></textarea></label>
        <div class="form-actions">
          <button class="button secondary correction-cancel" type="button">Cancel</button>
          <button class="button danger" type="submit">Void payment</button>
        </div>
      </form>
    </dialog>
    <style>
      .dialog-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
      .dialog-close{width:34px;height:34px;padding:0;color:#fff;background:transparent;border:1px solid rgba(255,255,255,.55);border-radius:8px;font-size:1.35rem;line-height:1;cursor:pointer}
      .dialog-close:hover{background:rgba(255,255,255,.15)}
      @media(max-width:600px){.records-table{min-width:760px}}
    </style>
    <script>
      (function () {
        var dialog = document.getElementById('payment-dialog');
        var form = document.getElementById('payment-form');
        function closeDialog() {
          if (form.dataset.dirty === '1' && !window.confirm('Discard this unsaved payment?')) return;
          dialog.close();
        }
        document.querySelectorAll('.payment-btn').forEach(function (button) {
          button.addEventListener('click', function () {
            form.reset();
            form.dataset.dirty = '0';
            document.getElementById('payment-invoice-id').value = button.dataset.id;
            document.getElementById('payment-amount').value = button.dataset.balance;
            document.getElementById('payment-amount').max = button.dataset.balance;
            dialog.showModal();
          });
        });
        form.addEventListener('input', function () { form.dataset.dirty = '1'; });
        form.addEventListener('change', function () { form.dataset.dirty = '1'; });
        form.addEventListener('submit', function () { form.dataset.dirty = '0'; });
        dialog.querySelector('.dialog-close').addEventListener('click', closeDialog);
        dialog.querySelector('.payment-cancel').addEventListener('click', closeDialog);
        dialog.addEventListener('cancel', function (event) {
          if (form.dataset.dirty === '1') {
            event.preventDefault();
            closeDialog();
          }
        });
        var correctionDialog = document.getElementById('correction-dialog');
        var correctionForm = document.getElementById('correction-form');
        function closeCorrectionDialog() {
          if (correctionForm.dataset.dirty === '1' && !window.confirm('Discard this unsaved correction?')) return;
          correctionDialog.close();
        }
        document.querySelectorAll('.correction-btn').forEach(function (button) {
          button.addEventListener('click', function () {
            correctionForm.reset();
            correctionForm.dataset.dirty = '0';
            document.getElementById('correction-payment-id').value = button.dataset.id;
            document.getElementById('correction-payment-label').textContent =
              button.dataset.reference + ' · ₱' + button.dataset.amount;
            correctionDialog.showModal();
          });
        });
        correctionForm.addEventListener('input', function () { correctionForm.dataset.dirty = '1'; });
        correctionForm.addEventListener('change', function () { correctionForm.dataset.dirty = '1'; });
        correctionForm.addEventListener('submit', function (event) {
          if (!window.confirm('Void this payment and recalculate the invoice balance?')) {
            event.preventDefault();
            return;
          }
          correctionForm.dataset.dirty = '0';
        });
        correctionDialog.querySelector('.dialog-close').addEventListener('click', closeCorrectionDialog);
        correctionDialog.querySelector('.correction-cancel').addEventListener('click', closeCorrectionDialog);
        correctionDialog.addEventListener('cancel', function (event) {
          if (correctionForm.dataset.dirty === '1') {
            event.preventDefault();
            closeCorrectionDialog();
          }
        });
        function bindFilter(inputId, rowSelector, emptyId, statusId) {
          var input = document.getElementById(inputId);
          if (!input) return;
          var status = statusId ? document.getElementById(statusId) : null;
          var rows = Array.prototype.slice.call(document.querySelectorAll(rowSelector));
          var empty = document.getElementById(emptyId);
          function apply() {
            var query = input.value.trim().toLowerCase();
            var selectedStatus = status ? status.value : '';
            var visible = 0;
            rows.forEach(function (row) {
              var matches = row.dataset.search.indexOf(query) !== -1
                && (!selectedStatus || row.dataset.status === selectedStatus);
              row.hidden = !matches;
              if (matches) visible++;
            });
            if (empty) empty.hidden = visible > 0;
          }
          input.addEventListener('input', apply);
          if (status) status.addEventListener('change', apply);
        }
        bindFilter('unbilled-search', '[data-unbilled-row]', 'unbilled-empty');
        bindFilter('invoice-search', '[data-invoice-row]', 'invoice-empty', 'invoice-status-filter');
        bindFilter('payment-search', '[data-payment-row]', 'payment-empty');
      })();
    </script>
    <?php
});
