<?php
require_once __DIR__ . '/../includes/role_layout.php';
require_role(['staff']);
$pdo=db();$error=$success='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 if(!csrf_valid(posted_string('csrf')))$error='Your session expired.';
 else{$action=posted_string('action');$name=trim(preg_replace('/\s+/',' ',posted_string('full_name')));$email=strtolower(trim(posted_string('email')));$phone=trim(posted_string('phone'));$address=trim(posted_string('address'));$id=(int)posted_string('owner_id');
  if(!valid_full_name($name)||mb_strlen($name)>100||($email!==''&&!valid_email($email)))$error='Enter a valid name and email.';
  else {
   try {
    $pdo->beginTransaction();
    if($action==='save'){
     if($id){$pdo->prepare('UPDATE PetOwners SET full_name=?,email=?,phone=?,address=? WHERE owner_id=?')->execute([$name,$email?:null,$phone?:null,$address?:null,$id]);$success='Pet owner updated.';}
     else{$pdo->prepare('INSERT INTO PetOwners(full_name,email,phone,address) VALUES(?,?,?,?)')->execute([$name,$email?:null,$phone?:null,$address?:null]);$success='Pet owner registered.';$id=(int)$pdo->lastInsertId();}
     role_log($pdo,(int)$_SESSION['user_id'],'owner.saved','owner',$id,$name);
     $pdo->commit();
    }
   } catch(Throwable$ex){
    if($pdo->inTransaction())$pdo->rollBack();
    $error=$ex->getCode()==='23000'?'That email is already registered.':'The pet owner could not be saved.';
   }
  }
 }
}
role_render('staff', ['owners'], 'Clients & Pets', 'Manage pet owners and their pets in one workspace', function (array $c) use ($pdo, $error, $success): void {
    $rows = $pdo->query(
        'SELECT o.*, COUNT(p.pet_id) AS pet_count
         FROM PetOwners o
         LEFT JOIN Pets p ON p.owner_id = o.owner_id
         GROUP BY o.owner_id
         ORDER BY o.full_name'
    )->fetchAll();
    if ($error !== '') {
        echo '<div class="notice error" role="alert">' . e($error) . '</div>';
    }
    if ($success !== '') {
        echo '<div class="notice success" role="status">' . e($success) . '</div>';
    }
    ?>
    <nav class="record-tabs" aria-label="Client and pet records">
      <a class="record-tab" href="owners.php" aria-current="page">Pet owners</a>
      <a class="record-tab" href="pets.php">Pets</a>
    </nav>
    <section class="panel owners-panel">
      <div class="panel-header">
        <h2>Pet owners</h2>
        <span class="data-count"><?= count($rows) ?> records</span>
      </div>
      <div class="panel-body owners-panel-body">
        <div class="owners-toolbar">
          <div class="owners-search-row" role="search">
            <label class="field" for="owner-search">Search pet owners
              <input id="owner-search" type="search" maxlength="100" autocomplete="off" placeholder="Search owner, email or phone">
            </label>
            <span class="owners-search-count" id="owner-search-count" aria-live="polite"></span>
          </div>
          <button class="button" type="button" onclick="openOwner()">+ Register owner</button>
        </div>
        <div class="table-wrap owners-table-wrap data-table-card">
          <table class="records-table owners-table" id="owner-table">
            <colgroup><col style="width:19%"><col style="width:21%"><col style="width:13%"><col style="width:23%"><col style="width:9%"><col></colgroup>
            <thead>
              <tr><th>Owner</th><th>Email</th><th>Phone</th><th>Address</th><th class="col-center">Pets</th><th>Action</th></tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <?php
                    $searchText = mb_strtolower($row['full_name'] . ' ' . ($row['email'] ?? '') . ' ' . ($row['phone'] ?? '')
                        . ' ' . ($row['address'] ?? ''));
                ?>
                <tr data-owner-row data-search="<?= e($searchText) ?>">
                  <td data-label="Owner"><?= e($row['full_name']) ?></td>
                  <td data-label="Email" title="<?= e($row['email'] ?: '—') ?>"><?= e($row['email'] ?: '—') ?></td>
                  <td data-label="Phone"><?= e($row['phone'] ?: '—') ?></td>
                  <td data-label="Address" title="<?= e($row['address'] ?: '—') ?>"><?= e($row['address'] ?: '—') ?></td>
                  <td data-label="Pets" class="col-center"><span class="owner-pet-count" aria-label="<?= (int) $row['pet_count'] ?> pet<?= (int) $row['pet_count'] === 1 ? '' : 's' ?>"><?= (int) $row['pet_count'] ?></span></td>
                  <td data-label="Action">
                    <div class="actions owners-row-actions">
                      <button class="button small secondary edit-owner" type="button"
                        data-id="<?= (int) $row['owner_id'] ?>"
                        data-name="<?= e($row['full_name']) ?>"
                        data-email="<?= e($row['email'] ?: '') ?>"
                        data-phone="<?= e($row['phone'] ?: '') ?>"
                        data-address="<?= e($row['address'] ?: '') ?>">Edit</button>
                      <a class="button small" href="pets.php?owner_id=<?= (int) $row['owner_id'] ?>">Add pet</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <tr class="owners-empty-row" id="owner-empty"<?= $rows ? ' hidden' : '' ?>>
                <td colspan="6">
                  <div class="owners-empty-state">
                    <strong><?= $rows ? 'No pet owners match your search' : 'No pet owners yet' ?></strong>
                    <small><?= $rows ? 'Try another owner name, email, phone or address.' : 'Register an owner to start building their pet records.' ?></small>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
    <dialog id="owner-dialog" aria-labelledby="owner-title">
      <div class="dialog-head">
        <h2 id="owner-title">Register owner</h2>
        <button class="dialog-close" type="button" aria-label="Close dialog">×</button>
      </div>
      <form class="dialog-form" id="owner-form" method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="owner_id" id="owner-id" value="0">
        <div class="form-grid">
          <label class="field full">Full name<input name="full_name" id="owner-name" required maxlength="100"></label>
          <label class="field">Email<input name="email" id="owner-email" type="email" maxlength="254"></label>
          <label class="field">Phone<input name="phone" id="owner-phone" maxlength="20"></label>
          <label class="field full">Address<input name="address" id="owner-address" maxlength="150"></label>
        </div>
        <div class="form-actions">
          <button class="button secondary owner-cancel" type="button">Cancel</button>
          <button class="button" type="submit">Save owner</button>
        </div>
      </form>
    </dialog>
    <style>
      .owners-panel-body{min-height:0;display:flex;flex-direction:column}
      .owners-toolbar{flex:none;margin:0 0 14px;display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px}
      .owners-search-row{min-width:0;width:auto;flex:1 1 320px;display:flex;align-items:flex-end;flex-wrap:wrap;gap:12px}
      .owners-search-row .field{max-width:none;flex:1 1 250px}
      .owners-search-count{min-height:41px;display:flex;align-items:center;color:var(--muted);font-size:.78rem;white-space:nowrap}
      .owners-toolbar>.button{flex:none;min-height:41px}
      .owners-table-wrap{flex:none;min-height:0;max-height:none;overflow-x:auto;overflow-y:hidden;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#91abc0 #edf3f8}
      .owners-table{width:100%;min-width:850px;table-layout:fixed}
      .owners-table th{position:sticky;top:0;z-index:1}
      .owners-table th:nth-child(1){width:20%}.owners-table th:nth-child(2){width:20%}
      .owners-table th:nth-child(3){width:13%}.owners-table th:nth-child(4){width:19%}
      .owners-table th:nth-child(5){width:8%}.owners-table th:nth-child(6){width:20%}
      .owner-pet-count{min-width:30px;height:30px;padding:0 8px;display:inline-flex;align-items:center;justify-content:center;color:#075985;background:#e6f2fa;border:1px solid #cfe3f1;border-radius:999px;font-weight:700;font-variant-numeric:tabular-nums}
      .owners-row-actions{flex-wrap:nowrap;gap:6px;white-space:nowrap}
      .owners-row-actions>*{flex:none}
      .owners-table-wrap::-webkit-scrollbar{width:9px;height:9px}
      .owners-table-wrap::-webkit-scrollbar-track{background:#edf3f8;border-radius:8px}
      .owners-table-wrap::-webkit-scrollbar-thumb{background:#91abc0;border:2px solid #edf3f8;border-radius:8px}
      .owners-empty-row td{background:#fff!important}
      .owners-empty-state{min-height:300px;padding:18px;display:grid;place-content:center;gap:5px;color:var(--muted);text-align:center}
      .owners-empty-state strong{color:#4d6f8a;font-size:.95rem}.owners-empty-state small{font-size:.78rem}
      .dialog-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
      .dialog-close{width:34px;height:34px;padding:0;color:#fff;background:transparent;border:1px solid rgba(255,255,255,.55);border-radius:8px;font-size:1.35rem;line-height:1;cursor:pointer}
      .dialog-close:hover{background:rgba(255,255,255,.15)}
      @media(max-width:600px){.owners-panel-body{height:auto;padding:12px}.owners-search-row .field{flex-basis:100%}.owners-search-count{min-height:0}.owners-toolbar>.button{align-self:flex-end}.owners-table{min-width:800px}}
    </style>
    <script>
      var ownerDialog = document.getElementById('owner-dialog');
      var ownerForm = document.getElementById('owner-form');

      function closeOwnerDialog() {
        if (ownerForm.dataset.dirty === '1' && !window.confirm('Discard unsaved owner changes?')) return;
        ownerDialog.close();
      }

      function resetOwnerForm() {
        ownerForm.reset();
        ownerForm.dataset.dirty = '0';
        document.getElementById('owner-id').value = '0';
        document.getElementById('owner-title').textContent = 'Register owner';
      }

      function openOwner() {
        resetOwnerForm();
        ownerDialog.showModal();
      }

      document.querySelectorAll('.edit-owner').forEach(function (button) {
        button.addEventListener('click', function () {
          resetOwnerForm();
          document.getElementById('owner-title').textContent = 'Edit owner';
          document.getElementById('owner-id').value = button.dataset.id;
          document.getElementById('owner-name').value = button.dataset.name;
          document.getElementById('owner-email').value = button.dataset.email;
          document.getElementById('owner-phone').value = button.dataset.phone;
          document.getElementById('owner-address').value = button.dataset.address;
          ownerForm.dataset.dirty = '0';
          ownerDialog.showModal();
        });
      });

      ownerForm.addEventListener('input', function () { ownerForm.dataset.dirty = '1'; });
      ownerForm.addEventListener('change', function () { ownerForm.dataset.dirty = '1'; });
      ownerForm.addEventListener('submit', function () { ownerForm.dataset.dirty = '0'; });
      ownerDialog.querySelector('.dialog-close').addEventListener('click', closeOwnerDialog);
      ownerDialog.querySelector('.owner-cancel').addEventListener('click', closeOwnerDialog);
      ownerDialog.addEventListener('cancel', function (event) {
        if (ownerForm.dataset.dirty === '1') {
          event.preventDefault();
          closeOwnerDialog();
        }
      });

      (function () {
        var input = document.getElementById('owner-search');
        var rows = Array.prototype.slice.call(document.querySelectorAll('[data-owner-row]'));
        var empty = document.getElementById('owner-empty');
        var count = document.getElementById('owner-search-count');

        function applyFilter() {
          var query = input.value.trim().toLocaleLowerCase();
          var shown = 0;
          rows.forEach(function (row) {
            var matches = !query || row.dataset.search.indexOf(query) !== -1;
            row.hidden = !matches;
            row.classList.toggle('alt', matches && shown % 2 === 1);
            if (matches) shown++;
          });
          count.textContent = shown + ' of ' + rows.length + ' shown';
          empty.hidden = rows.length > 0 && (shown !== 0 || query === '');
        }

        input.addEventListener('input', applyFilter);
        applyFilter();
      })();
    </script>
    <?php
});