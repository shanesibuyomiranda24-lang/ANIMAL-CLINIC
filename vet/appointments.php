<?php
require_once __DIR__ . '/../includes/role_layout.php';
require_once __DIR__ . '/../includes/pet_photos.php';
require_role(['vet']);

$pdo = db();
$vetStatement = $pdo->prepare('SELECT vet_id FROM Users WHERE user_id = ?');
$vetStatement->execute([(int) $_SESSION['user_id']]);
$vetId = (int) $vetStatement->fetchColumn();

role_render('vet', ['appointments'], 'My Appointments', 'Appointments assigned to you', function (array $context) use ($pdo, $vetId): void {
    $statement = $pdo->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.duration_minutes, a.status, a.client_notes,
                p.pet_id, p.pet_name, p.species, p.breed, p.photo_updated_at,
                o.full_name AS owner_name,
                (SELECT GROUP_CONCAT(s.service_name ORDER BY s.service_name SEPARATOR ', ')
                   FROM AppointmentServices aps
                   JOIN Services s ON s.service_id = aps.service_id
                  WHERE aps.appointment_id = a.appointment_id) AS services
         FROM Appointments a
         JOIN Pets p ON p.pet_id = a.pet_id
         JOIN PetOwners o ON o.owner_id = p.owner_id
         WHERE a.vet_id = ?
         ORDER BY (a.appointment_date >= CURRENT_DATE) DESC,
                  CASE WHEN a.appointment_date >= CURRENT_DATE THEN a.appointment_date END ASC,
                  a.appointment_date DESC"
    );
    $statement->execute([$vetId]);
    $appointments = $statement->fetchAll();

    $todayKey = date('Y-m-d');
    $tomorrowKey = date('Y-m-d', strtotime('+1 day'));
    $filters = [
        'all'       => ['label' => 'All', 'count' => 0],
        'today'     => ['label' => 'Today', 'count' => 0],
        'upcoming'  => ['label' => 'Upcoming', 'count' => 0],
        'pending'   => ['label' => 'Pending', 'count' => 0],
        'clinic'    => ['label' => 'In clinic', 'count' => 0],
        'complete'  => ['label' => 'Completed', 'count' => 0],
        'cancelled' => ['label' => 'Cancelled', 'count' => 0],
    ];
    foreach ($appointments as $index => $row) {
        $dateKey = substr((string) $row['appointment_date'], 0, 10);
        $status = (string) $row['status'];
        $groups = ['all'];
        if ($dateKey === $todayKey && $status !== 'Cancelled') {
            $groups[] = 'today';
        }
        if ($dateKey >= $tomorrowKey && in_array($status, ['Pending', 'Scheduled'], true)) {
            $groups[] = 'upcoming';
        }
        if ($status === 'Pending') {
            $groups[] = 'pending';
        }
        if (in_array($status, ['Waiting', 'Check-in', 'In Consultation'], true)) {
            $groups[] = 'clinic';
        }
        if ($status === 'Complete') {
            $groups[] = 'complete';
        }
        if ($status === 'Cancelled') {
            $groups[] = 'cancelled';
        }
        foreach ($groups as $group) {
            $filters[$group]['count']++;
        }
        $appointments[$index]['_groups'] = implode(' ', $groups);
        $appointments[$index]['_date_key'] = $dateKey;
    }
    ?>
    <style>
      .appt-controls{padding:16px;background:#f4f9fd;border:1px solid #d8e9f5;border-radius:12px}
      .appt-filters{display:flex;flex-wrap:wrap;gap:.55rem;margin:0 0 14px;padding:0 0 14px;border-bottom:1px solid #d9e7f1}
      .appt-filters .button{min-height:40px;padding:7px 12px;display:inline-flex;align-items:center;gap:.5rem;color:#315d7c;background:#fff;border:1px solid #d2e2ee;border-radius:999px;font-size:.8rem}
      .appt-filters .button[aria-pressed="true"]{color:#fff;background:#006a9f;border-color:#006a9f}
      .appt-filters .button:focus-visible,.appt-clear:focus-visible{outline:3px solid rgba(2,132,199,.24);outline-offset:2px}
      .appt-chip-count{min-width:1.55rem;height:1.55rem;padding:0 .4rem;display:inline-grid;place-items:center;color:#48677e;background:#e9f1f7;border-radius:999px;font-size:.74rem;font-weight:700;line-height:1}
      .appt-filters .button[aria-pressed="true"] .appt-chip-count{color:#fff;background:rgba(255,255,255,.2)}
      .appt-toolbar{display:grid;grid-template-columns:minmax(240px,1.4fr) minmax(170px,.8fr) minmax(170px,.8fr) auto;align-items:end;gap:12px}
      .appt-toolbar .field{min-width:0;max-width:none}
      .appt-clear{min-width:82px;min-height:42px}
      .appt-results-count{margin-top:10px;display:flex;justify-content:flex-end}
      .appt-when strong{display:block}
      .appt-when small,.appt-service small,.vet-pet-cell small{display:block;opacity:.75}
      .appt-service-preview{display:block}
      .appt-service-view-all{margin-top:4px}
      .appt-services-dialog{width:min(36rem,calc(100vw - 2rem));max-height:80vh;padding:1.25rem;color:var(--ink);border:1px solid var(--line);border-radius:14px;box-shadow:0 18px 50px rgba(0,46,74,.25)}
      .appt-services-dialog::backdrop{background:rgba(0,28,46,.55)}
      .appt-services-dialog h2{margin:0 0 .75rem;font-size:1.1rem}
      .appt-services-dialog p{max-height:55vh;margin:0 0 1rem;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere}
      .appt-services-dialog form{display:flex;justify-content:flex-end}
      .appt-day-tag{display:inline-block;margin-bottom:.2rem;font-size:.75rem;font-weight:700}
      .appt-notes{display:block;max-width:26ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .appt-actions{display:flex;flex-wrap:wrap;gap:.4rem}
      .appt-pager{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;padding:1rem 1.25rem}
      .appt-pager[hidden]{display:none}
      .appt-pager-buttons{display:flex;gap:.5rem}
      tr.appt-today td:first-child{box-shadow:inset 3px 0 0 #0284c7}
      tr.appt-cancelled{opacity:.7}
      @media (max-width:760px){.appt-toolbar{grid-template-columns:1fr}.appt-clear{width:100%}.appt-results-count{justify-content:flex-start}.appt-actions{margin-top:.25rem}}
    </style>

    <section class="panel">
      <div class="panel-header"><h2>Assigned appointments</h2><span class="data-count" id="appt-total"><?= count($appointments) ?> visit<?= count($appointments) === 1 ? '' : 's' ?></span></div>

      <div class="panel-body">
        <div class="appt-controls">
          <div class="appt-filters" role="group" aria-label="Filter appointments">
            <?php foreach ($filters as $key => $filter): ?>
              <button type="button" class="button" data-appt-filter="<?= e($key) ?>" aria-pressed="<?= $key === 'all' ? 'true' : 'false' ?>">
                <?= e($filter['label']) ?> <span class="appt-chip-count"><?= (int) $filter['count'] ?></span>
              </button>
            <?php endforeach; ?>
          </div>
          <div class="appt-toolbar">
            <label class="field">Search appointments
              <input type="search" id="appt-search" placeholder="Pet, owner, service or status" autocomplete="off">
            </label>
            <label class="field">Status
              <select id="appt-status">
                <option value="">All statuses</option>
                <option value="Pending">Pending</option>
                <option value="Scheduled">Scheduled</option>
                <option value="Waiting">Waiting</option>
                <option value="Check-in">Check-in</option>
                <option value="In Consultation">In consultation</option>
                <option value="Complete">Completed</option>
                <option value="Cancelled">Cancelled</option>
                <option value="Rejected">Rejected</option>
              </select>
            </label>
            <label class="field">Date
              <input type="date" id="appt-date">
            </label>
            <button class="button secondary appt-clear" type="button" id="appt-clear" aria-label="Clear appointment filters">Clear</button>
          </div>
          <div class="appt-results-count"><span class="table-search-count" id="appt-count" aria-live="polite"></span></div>
        </div>
      </div>

      <div class="table-wrap data-table-card">
        <table class="records-table" id="appt-table">
          <colgroup><col style="width:15%"><col style="width:19%"><col style="width:17%"><col style="width:23%"><col style="width:12%"><col style="width:14%"></colgroup>
          <thead><tr><th>Date &amp; time</th><th>Pet</th><th>Owner</th><th>Services</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody id="appt-body">
            <?php foreach ($appointments as $appointment): ?>
              <?php
                $photoUrl = pet_photo_url($appointment['photo_updated_at'] ?? null, (int) $appointment['pet_id']);
                $timestamp = strtotime((string) $appointment['appointment_date']);
                $dateKey = $appointment['_date_key'];
                $dayTag = $dateKey === $todayKey ? 'Today' : ($dateKey === $tomorrowKey ? 'Tomorrow' : '');
                $status = (string) $appointment['status'];
                $canConsult = in_array($status, ['Check-in', 'In Consultation'], true);
                $petLine = trim(($appointment['species'] ?? '') . (!empty($appointment['breed']) ? ' · ' . $appointment['breed'] : ''));
                $haystack = mb_strtolower(implode(' ', [
                    $appointment['pet_name'], $appointment['owner_name'], $appointment['services'] ?? '',
                    $status, date('M j Y l g:i A', $timestamp), $petLine,
                ]));
                $rowClass = trim(($dateKey === $todayKey && $status !== 'Cancelled' ? 'appt-today ' : '') . ($status === 'Cancelled' ? 'appt-cancelled' : ''));
              ?>
              <tr class="<?= e($rowClass) ?>" data-appt-row data-groups="<?= e($appointment['_groups']) ?>" data-status="<?= e($status) ?>" data-date="<?= e($dateKey) ?>" data-search="<?= e($haystack) ?>">
                <td class="appt-when" data-label="Date &amp; time">
                  <?php if ($dayTag !== ''): ?><span class="appt-day-tag"><?= e($dayTag) ?></span><?php endif; ?>
                  <strong><?= e(date('M j, Y', $timestamp)) ?></strong>
                  <small><?= e(date('g:i A', $timestamp)) ?><?= !empty($appointment['duration_minutes']) ? ' · ' . (int) $appointment['duration_minutes'] . ' min' : '' ?></small>
                </td>
                <td class="vet-pet-cell" data-label="Pet">
                  <?php if ($photoUrl !== ''): ?>
                    <img class="vet-pet-thumb" src="<?= e($photoUrl) ?>" alt="Photo of <?= e($appointment['pet_name']) ?>" loading="lazy">
                  <?php else: ?>
                    <span class="vet-pet-thumb vet-pet-thumb-empty" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($appointment['pet_name'], 0, 1))) ?></span>
                  <?php endif; ?>
                  <span><strong><?= e($appointment['pet_name']) ?></strong><?php if ($petLine !== ''): ?><small><?= e($petLine) ?></small><?php endif; ?></span>
                </td>
                <td data-label="Owner"><?= e($appointment['owner_name']) ?></td>
                <td class="appt-service" data-label="Services">
                  <span class="appt-service-preview"><?= e($appointment['services'] ?: '—') ?></span>
                  <button class="button secondary small appt-service-view-all" type="button" data-view-services="<?= e($appointment['services'] ?: '—') ?>">View all</button>
                  <?php if (!empty($appointment['client_notes'])): ?><small class="appt-notes" title="<?= e($appointment['client_notes']) ?>">Note: <?= e($appointment['client_notes']) ?></small><?php endif; ?>
                </td>
                <td data-label="Status"><span class="status <?= e(role_status_class($status)) ?>"><?= e($status) ?></span></td>
                <td data-label="Actions">
                  <div class="appt-actions">
                    <?php if ($canConsult): ?>
                      <a class="button small" href="medical.php?appointment=<?= (int) $appointment['appointment_id'] ?>"><?= $status === 'In Consultation' ? 'Continue' : 'Start' ?></a>
                    <?php endif; ?>
                    <a class="button secondary small" href="medical.php?pet=<?= (int) $appointment['pet_id'] ?>">History</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <tr id="appt-empty" class="empty-row" <?= $appointments ? 'hidden' : '' ?>>
              <td colspan="6">
                <div class="empty-state-box">
                  <strong id="appt-empty-title"><?= $appointments ? 'No appointments match' : 'No assigned appointments yet' ?></strong>
                  <small id="appt-empty-text"><?= $appointments ? 'Try a different filter or search term.' : 'Visits assigned to you will appear here, and you will be notified when one is booked, rescheduled or cancelled.' ?></small>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="appt-pager" id="appt-pager" <?= count($appointments) > 10 ? '' : 'hidden' ?>>
        <span id="appt-range" aria-live="polite"></span>
        <div class="appt-pager-buttons">
          <button class="button secondary small" type="button" id="appt-prev">Previous</button>
          <button class="button secondary small" type="button" id="appt-next">Next</button>
        </div>
      </div>
    </section>

    <dialog class="appt-services-dialog" id="appt-services-dialog" aria-labelledby="appt-services-title">
      <h2 id="appt-services-title">Appointment services</h2>
      <p id="appt-services-content"></p>
      <form method="dialog"><button class="button secondary" type="submit">Close</button></form>
    </dialog>

    <script>
    (function(){
      var PAGE_SIZE=10;
      var rows=Array.prototype.slice.call(document.querySelectorAll('[data-appt-row]'));
      var chips=Array.prototype.slice.call(document.querySelectorAll('[data-appt-filter]'));
      var search=document.getElementById('appt-search'),statusInput=document.getElementById('appt-status');
      var dateInput=document.getElementById('appt-date');
      var clear=document.getElementById('appt-clear'),count=document.getElementById('appt-count');
      var emptyRow=document.getElementById('appt-empty'),emptyTitle=document.getElementById('appt-empty-title'),emptyText=document.getElementById('appt-empty-text');
      var pager=document.getElementById('appt-pager'),range=document.getElementById('appt-range');
      var prev=document.getElementById('appt-prev'),next=document.getElementById('appt-next');
      var servicesDialog=document.getElementById('appt-services-dialog'),servicesContent=document.getElementById('appt-services-content');
      var filter='all',page=1;

      document.querySelectorAll('[data-view-services]').forEach(function(button){
        button.addEventListener('click',function(){
          servicesContent.textContent=button.dataset.viewServices||'—';
          servicesDialog.showModal();
        });
      });

      function render(){
        var term=search.value.trim().toLowerCase();
        var selectedStatus=statusInput.value;
        var selectedDate=dateInput.value;
        var matches=rows.filter(function(row){
          var inGroup=(' '+row.dataset.groups+' ').indexOf(' '+filter+' ')!==-1;
          var onStatus=!selectedStatus||row.dataset.status===selectedStatus;
          var onDate=!selectedDate||row.dataset.date===selectedDate;
          return inGroup&&onStatus&&onDate&&(!term||row.dataset.search.indexOf(term)!==-1);
        });
        var pages=Math.max(1,Math.ceil(matches.length/PAGE_SIZE));
        if(page>pages)page=pages;
        var start=(page-1)*PAGE_SIZE,end=start+PAGE_SIZE;
        rows.forEach(function(row){row.hidden=true});
        matches.slice(start,end).forEach(function(row){row.hidden=false});

        if(!rows.length){
          emptyRow.hidden=false;
          pager.hidden=true;
          range.textContent='';
          count.textContent='0 of 0 visits';
          return;
        }
        emptyRow.hidden=matches.length>0;
        if(!matches.length){
          emptyTitle.textContent='No appointments match';
          emptyText.textContent=term?'Nothing matches "'+search.value.trim()+'". Try a different search or filter.':'There are no visits in this view.';
        }
        pager.hidden=matches.length<=PAGE_SIZE;
        range.textContent='Showing '+(matches.length?start+1:0)+'–'+Math.min(end,matches.length)+' of '+matches.length;
        prev.disabled=page<=1;next.disabled=page>=pages;
        count.textContent=matches.length+' of '+rows.length+' visit'+(rows.length===1?'':'s');
      }

      chips.forEach(function(chip){
        chip.addEventListener('click',function(){
          filter=chip.dataset.apptFilter;page=1;
          chips.forEach(function(other){
            var on=other===chip;
            other.setAttribute('aria-pressed',on?'true':'false');
            other.classList.toggle('secondary',!on);
          });
          render();
        });
      });
      search.addEventListener('input',function(){page=1;render()});
      statusInput.addEventListener('change',function(){page=1;render()});
      dateInput.addEventListener('change',function(){page=1;render()});
      clear.addEventListener('click',function(){
        search.value='';
        statusInput.value='';
        dateInput.value='';
        filter='all';
        page=1;
        chips.forEach(function(chip){
          var active=chip.dataset.apptFilter==='all';
          chip.setAttribute('aria-pressed',active?'true':'false');
        });
        render();
      });
      prev.addEventListener('click',function(){if(page>1){page--;render()}});
      next.addEventListener('click',function(){page++;render()});
      render();
    })();
    </script>
    <?php
});
