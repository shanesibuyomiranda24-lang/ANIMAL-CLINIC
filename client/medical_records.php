<?php
require_once __DIR__ . '/client_layout.php';

$client = client_boot();
$pdo = $client['pdo'];
$rows = [];
$loadError = false;
$petNotFound = false;
$selectedPetId = null;
$selectedPetName = '';

if (!$client['dbError'] && array_key_exists('pet', $_GET)) {
    $rawPetId = $_GET['pet'];
    $petId = is_string($rawPetId)
        ? filter_var($rawPetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    if ($petId === false) {
        $petNotFound = true;
    } else {
        try {
            $petStatement = $pdo->prepare(
                'SELECT pet_id, pet_name FROM Pets WHERE pet_id = ? AND owner_id = ?'
            );
            $petStatement->execute([(int) $petId, (int) $client['ownerId']]);
            $pet = $petStatement->fetch();
            if ($pet) {
                $selectedPetId = (int) $pet['pet_id'];
                $selectedPetName = (string) $pet['pet_name'];
            } else {
                $petNotFound = true;
            }
        } catch (Throwable $ex) {
            error_log('Client medical history pet lookup: ' . $ex->getMessage());
            $loadError = true;
        }
    }
}

if (!$client['dbError'] && !$loadError && !$petNotFound) {
    try {
        $sql = 'SELECT mr.record_id, mr.symptoms, mr.examination, mr.diagnosis, mr.treatment,
                       mr.recommendations, mr.follow_up_date, mr.finalized_at,
                       a.appointment_date, p.pet_id, p.pet_name
                FROM MedicalRecords mr
                JOIN Appointments a ON a.appointment_id = mr.appointment_id
                JOIN Pets p ON p.pet_id = a.pet_id
                WHERE p.owner_id = ? AND mr.is_finalized = 1 AND a.status = \'Complete\'';
        $params = [(int) $client['ownerId']];
        if ($selectedPetId !== null) {
            $sql .= ' AND p.pet_id = ?';
            $params[] = $selectedPetId;
        }
        $sql .= ' ORDER BY a.appointment_date DESC, mr.record_id DESC';
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();
    } catch (Throwable $ex) {
        error_log('Client medical history query: ' . $ex->getMessage());
        $loadError = true;
    }
}

$subtitle = $selectedPetName !== ''
    ? 'Completed consultation records for ' . $selectedPetName
    : 'Completed consultation records for your pets';
client_header($client, 'Medical History', $subtitle, 'medical');
?>
        <?php if ($loadError): ?>
          <div class="notice error" role="alert">Medical records could not be loaded. Please try again later.</div>
        <?php endif; ?>
        <?php if ($petNotFound): ?>
          <div class="notice error" role="alert">That pet could not be found in your account.</div>
        <?php endif; ?>
        <section class="panel appointment-panel">
          <div class="panel-header">
            <h2><?= $selectedPetName !== '' ? e($selectedPetName) . ' · ' : '' ?>Completed consultations</h2>
            <span class="data-count"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?></span>
          </div>
          <div class="panel-body appointment-panel-body">
            <?php client_search('medical-search', 'Find a medical record', 'Search pet, diagnosis or treatment…', 'medical-record-body', 'medical-search-count', 'medical-record-empty'); ?>
            <div class="table-wrap appointment-table-wrap data-table-card">
              <table class="records-table appointment-table">
                <colgroup><col style="width:18%"><col style="width:16%"><col style="width:22%"><col style="width:17%"><col style="width:27%"></colgroup>
                <thead>
                  <tr><th>Visit date</th><th>Pet</th><th>Diagnosis</th><th>Follow-up</th><th>Record details</th></tr>
                </thead>
                <tbody id="medical-record-body">
                  <?php if ($rows): ?>
                    <?php foreach ($rows as $index => $record): ?>
                      <tr data-search-item<?= $index % 2 ? ' class="alt"' : '' ?>>
                        <td data-label="Visit date"><?= e(date('M j, Y g:i A', strtotime($record['appointment_date']))) ?></td>
                        <td data-label="Pet"><?= e($record['pet_name']) ?></td>
                        <td data-label="Diagnosis"><?= e($record['diagnosis']) ?></td>
                        <td data-label="Follow-up"><?= $record['follow_up_date'] ? e(date('M j, Y', strtotime($record['follow_up_date']))) : '—' ?></td>
                        <td data-label="Record details">
                          <details>
                            <summary>View record</summary>
                            <dl class="medical-details">
                              <dt>Symptoms</dt><dd><?= $record['symptoms'] !== null && $record['symptoms'] !== '' ? nl2br(e($record['symptoms'])) : '—' ?></dd>
                              <dt>Examination</dt><dd><?= $record['examination'] !== null && $record['examination'] !== '' ? nl2br(e($record['examination'])) : '—' ?></dd>
                              <dt>Treatment</dt><dd><?= $record['treatment'] !== null && $record['treatment'] !== '' ? nl2br(e($record['treatment'])) : '—' ?></dd>
                              <dt>Recommendations</dt><dd><?= $record['recommendations'] !== null && $record['recommendations'] !== '' ? nl2br(e($record['recommendations'])) : '—' ?></dd>
                            </dl>
                          </details>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <tr class="empty-row" id="medical-record-empty" data-search-empty hidden>
                      <td colspan="5"><div class="empty-state-box"><strong>No records match your search</strong><small>Try another pet, diagnosis or treatment.</small></div></td>
                    </tr>
                  <?php else: ?>
                    <tr class="appointment-empty-row">
                      <td colspan="5">
                        <div class="appointment-empty-state">
                          <strong><?= $selectedPetName !== '' ? 'No completed consultations for ' . e($selectedPetName) : 'No completed consultation records yet' ?></strong>
                          <span>Finalized records from completed appointments will appear here.</span>
                        </div>
                      </td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>
        <style>
          .medical-details{min-width:240px;max-width:520px;margin:10px 0;display:grid;grid-template-columns:110px minmax(0,1fr);gap:6px 10px}
          .medical-details dt{color:var(--blue-900);font-size:.76rem;font-weight:700}
          .medical-details dd{margin:0;color:#244661;font-size:.8rem;white-space:normal}
          td details summary{color:var(--blue-700);font-weight:700;cursor:pointer}
        </style>
<?php client_footer($client); ?>
