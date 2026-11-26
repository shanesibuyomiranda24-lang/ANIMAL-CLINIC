<?php
// client/vaccinations.php - vaccination log per pet
// Column names are matched flexibly; adjust the lists below if your Vaccinations table uses others.
require_once __DIR__ . '/client_layout.php';
$c = client_boot();
$pdo = $c['pdo'];
$rows = [];
$loadError = false;
$petNotFound = false;
$selectedPetId = null;
$selectedPetName = '';
if (!$c['dbError'] && array_key_exists('pet', $_GET)) {
    $requestedPetId = filter_var($_GET['pet'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($requestedPetId === false) {
        $petNotFound = true;
    } else {
        try {
            $petStatement = $pdo->prepare('SELECT pet_id, pet_name FROM Pets WHERE pet_id = ? AND owner_id = ?');
            $petStatement->execute([(int) $requestedPetId, (int) $c['ownerId']]);
            $selectedPet = $petStatement->fetch();
            if ($selectedPet) {
                $selectedPetId = (int) $selectedPet['pet_id'];
                $selectedPetName = (string) $selectedPet['pet_name'];
            } else {
                $petNotFound = true;
            }
        } catch (Throwable $ex) {
            error_log('vaccination pet lookup: ' . $ex->getMessage());
            $loadError = true;
        }
    }
}
if (!$c['dbError'] && !$loadError && !$petNotFound) {
    try {
        $sql = 'SELECT v.*, p.pet_name FROM Vaccinations v JOIN Pets p ON p.pet_id = v.pet_id WHERE p.owner_id = ?';
        $params = [(int) $c['ownerId']];
        if ($selectedPetId !== null) {
            $sql .= ' AND p.pet_id = ?';
            $params[] = $selectedPetId;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();
    } catch (Throwable $ex) {
        error_log('vaccinations: ' . $ex->getMessage());
        $loadError = true;
    }
}
$items = [];
$today = date('Y-m-d');
foreach ($rows as $r) {
    $given = (string) pick($r, ['date_given', 'vaccination_date', 'date_administered', 'given_date', 'date']);
    $due = (string) pick($r, ['next_due_date', 'next_due', 'due_date', 'next_vaccination_date']);
    $items[] = ['pet' => $r['pet_name'], 'vaccine' => (string) pick($r, ['vaccination_name', 'vaccine_name', 'vaccine', 'name'], 'Vaccination'), 'given' => $given, 'due' => $due,
                'status' => $due !== '' && $due < $today ? 'Overdue' : ($due !== '' ? 'Upcoming' : 'Up to date')];
}
usort($items, fn($a, $b) => strcmp($b['given'], $a['given']));
$dueSoon = array_values(array_filter($items, fn($i) => $i['due'] !== '' && $i['due'] <= date('Y-m-d', strtotime('+30 days'))));
usort($dueSoon, fn($a, $b) => strcmp($a['due'], $b['due']));
$subtitle = $selectedPetName !== '' ? 'Vaccination history for ' . $selectedPetName : 'Vaccines given and what is due next';
client_header($c, 'Vaccinations', $subtitle, 'pets');
?>
        <?php if ($loadError): ?><div class="notice error" role="alert">Vaccination records could not be loaded. Check the Vaccinations table and its column names.</div><?php endif; ?>
        <?php if ($petNotFound): ?><div class="notice error" role="alert">That pet could not be found in your account.</div><?php endif; ?>
        <?php if ($dueSoon): ?>
        <section class="panel">
          <div class="panel-header"><h2><?= $selectedPetName !== '' ? e($selectedPetName) . ' · ' : '' ?>Due soon or overdue</h2><span class="data-count">Next 30 days</span></div>
          <div class="table-wrap vaccination-due-wrap data-table-card"><table class="records-table appointment-table vaccination-due-table"><colgroup><col style="width:24%"><col style="width:28%"><col style="width:20%"><col style="width:28%"></colgroup><thead><tr><th>Pet</th><th>Vaccine</th><th class="col-center">Due date</th><th class="col-center">Status</th></tr></thead><tbody>
            <?php foreach ($dueSoon as $i => $d): ?>
              <tr<?= $i % 2 ? ' class="alt"' : '' ?>><td data-label="Pet"><?= e($d['pet']) ?></td><td data-label="Vaccine"><?= e($d['vaccine']) ?></td><td class="col-center" data-label="Due date"><?= e(date('M j, Y', strtotime($d['due']))) ?></td>
                <td class="col-center" data-label="Status"><span class="status <?= $d['status'] === 'Overdue' ? 'overdue' : 'scheduled' ?>"><?= e($d['status']) ?></span></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <div class="panel-body"><a class="button small" href="book_appointment.php">Book a vaccination</a></div>
        </section>
        <?php endif; ?>
        <section class="panel appointment-panel">
          <div class="panel-header"><h2><?= $selectedPetName !== '' ? e($selectedPetName) . ' · vaccination history' : 'Vaccination log' ?></h2><span class="data-count"><?= count($items) ?> record<?= count($items) === 1 ? '' : 's' ?></span></div>
          <div class="panel-body appointment-panel-body">
            <?php client_search('vaccination-search', 'Find a vaccine record', 'Search pet, vaccine or status…', 'vaccination-body', 'vaccination-count', 'vaccination-empty'); ?>
            <div class="table-wrap appointment-table-wrap data-table-card">
              <table class="records-table appointment-table vaccination-history-table">
                <colgroup><col style="width:18%"><col style="width:24%"><col style="width:20%"><col style="width:20%"><col style="width:18%"></colgroup>
                <thead><tr><th>Pet</th><th>Vaccine</th><th class="col-center">Date given</th><th class="col-center">Next due</th><th class="col-center">Status</th></tr></thead>
                <tbody id="vaccination-body">
                  <?php if ($items): ?>
                    <?php foreach ($items as $i => $d): ?>
                      <tr data-search-item<?= $i % 2 ? ' class="alt"' : '' ?>><td data-label="Pet"><?= e($d['pet']) ?></td><td data-label="Vaccine"><?= e($d['vaccine']) ?></td>
                        <td class="col-center" data-label="Date given"><?= $d['given'] !== '' ? e(date('M j, Y', strtotime($d['given']))) : '—' ?></td>
                        <td class="col-center" data-label="Next due"><?= $d['due'] !== '' ? e(date('M j, Y', strtotime($d['due']))) : '—' ?></td>
                        <td class="col-center" data-label="Status"><span class="status <?= $d['status'] === 'Overdue' ? 'overdue' : ($d['status'] === 'Upcoming' ? 'scheduled' : '') ?>"><?= e($d['status']) ?></span></td></tr>
                    <?php endforeach; ?>
                    <tr class="empty-row" id="vaccination-empty" data-search-empty hidden><td colspan="5"><div class="empty-state-box"><strong>No records match your search</strong><small>Try another pet, vaccine or status.</small></div></td></tr>
                  <?php else: ?>
                    <tr class="appointment-empty-row"><td colspan="5"><div class="appointment-empty-state">
                      <strong><?= $petNotFound ? 'Pet not found' : ($loadError ? 'Vaccination history unavailable' : ($selectedPetName !== '' ? 'No vaccinations recorded for ' . e($selectedPetName) : 'No vaccinations recorded yet')) ?></strong>
                      <span><?= $petNotFound ? 'Choose one of your pet profiles from My Pets to view its history.' : 'Vaccines given at the clinic will appear here with their next due date.' ?></span>
                    </div></td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>
<?php client_footer($c);