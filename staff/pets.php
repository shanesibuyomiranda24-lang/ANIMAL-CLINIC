<?php
require_once __DIR__ . '/../includes/role_layout.php';
require_once __DIR__ . '/../includes/pet_photos.php';
require_role(['staff']);

$pdo = db();
$error = '';
$success = '';
$accountStatement = $pdo->prepare(
    "SELECT s.`role` AS staff_role, g.groomer_id
     FROM Users u
     JOIN Staff s ON s.staff_id = u.staff_id
     LEFT JOIN Groomers g ON g.user_id = u.user_id
     WHERE u.user_id = ? AND u.`role` = 'staff'
     LIMIT 1"
);
$accountStatement->execute([(int) ($_SESSION['user_id'] ?? 0)]);
$account = $accountStatement->fetch() ?: [];
$isGroomerAccount = ($account['staff_role'] ?? '') === 'Groomer';
$groomerId = (int) ($account['groomer_id'] ?? 0);
if ($isGroomerAccount && basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) !== 'groomer') {
    header('Location: ../groomer/pets.php');
    exit;
}
$requestedPetId = filter_var(
    $_GET['pet'] ?? '',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$requestedPetId = $requestedPetId === false ? 0 : (int) $requestedPetId;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($isGroomerAccount) {
        http_response_code(403);
        exit('Groomer accounts can only view pet records.');
    } elseif (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif (posted_string('action') !== 'save') {
        $error = 'Unsupported pet request.';
    } else {
        $petId = filter_var(posted_string('pet_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $ownerId = filter_var(posted_string('owner_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $name = trim(posted_string('pet_name'));
        $species = trim(posted_string('species'));
        $breed = trim(posted_string('breed'));
        $gender = posted_string('gender');
        $birthDate = posted_string('birth_date');

        try {
            if ($petId === false || $ownerId === false || $name === '' || mb_strlen($name) > 50
                || $species === '' || mb_strlen($species) > 50 || mb_strlen($breed) > 50) {
                throw new DomainException('Choose an owner and enter valid pet details.');
            }
            if (!in_array($gender, ['', 'Male', 'Female'], true)) {
                throw new DomainException('Choose a valid sex value.');
            }
            if ($birthDate !== '') {
                $parsedBirthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
                if (!$parsedBirthDate || $parsedBirthDate->format('Y-m-d') !== $birthDate
                    || $parsedBirthDate > new DateTimeImmutable('today')) {
                    throw new DomainException('Enter a valid birth date that is not in the future.');
                }
            }
            $photo = pet_photo_read_upload($_FILES['pet_photo'] ?? []);

            $pdo->beginTransaction();
            $ownerStatement = $pdo->prepare('SELECT owner_id FROM PetOwners WHERE owner_id = ? FOR UPDATE');
            $ownerStatement->execute([(int) $ownerId]);
            if (!$ownerStatement->fetchColumn()) {
                throw new DomainException('That pet owner no longer exists.');
            }

            if ((int) $petId > 0) {
                $petStatement = $pdo->prepare('SELECT pet_id FROM Pets WHERE pet_id = ? AND archived_at IS NULL FOR UPDATE');
                $petStatement->execute([(int) $petId]);
                if (!$petStatement->fetchColumn()) {
                    throw new DomainException('That pet record no longer exists.');
                }
                $pdo->prepare(
                    'UPDATE Pets
                     SET owner_id = ?, pet_name = ?, species = ?, breed = ?, birth_date = ?, gender = ?
                     WHERE pet_id = ?'
                )->execute([
                    (int) $ownerId,
                    $name,
                    $species,
                    $breed !== '' ? $breed : null,
                    $birthDate !== '' ? $birthDate : null,
                    $gender !== '' ? $gender : null,
                    (int) $petId,
                ]);
                $savedPetId = (int) $petId;
            } else {
                $pdo->prepare(
                    'INSERT INTO Pets (owner_id, pet_name, species, breed, birth_date, gender)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    (int) $ownerId,
                    $name,
                    $species,
                    $breed !== '' ? $breed : null,
                    $birthDate !== '' ? $birthDate : null,
                    $gender !== '' ? $gender : null,
                ]);
                $savedPetId = (int) $pdo->lastInsertId();
            }

            if ($photo !== null) {
                pet_photo_save($pdo, $savedPetId, $photo);
            } elseif (posted_string('remove_photo') === '1') {
                pet_photo_save($pdo, $savedPetId, null);
            }

            role_log($pdo, (int) $_SESSION['user_id'], 'pet.saved', 'pet', $savedPetId, $name);
            $pdo->commit();
            $success = 'Pet record saved.';
        } catch (DomainException | InvalidArgumentException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Staff pet action: ' . $exception->getMessage());
            $error = 'The pet record could not be saved. Check the owner and photo, then try again.';
        }
    }
}

$owners = $pdo->query('SELECT owner_id, full_name FROM PetOwners ORDER BY full_name')->fetchAll();
$selectedOwnerId = filter_var(
    $_GET['owner_id'] ?? '',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$selectedOwnerId = $selectedOwnerId === false ? 0 : (int) $selectedOwnerId;
if ($selectedOwnerId > 0) {
    $ownerIds = array_map('intval', array_column($owners, 'owner_id'));
    if (!in_array($selectedOwnerId, $ownerIds, true)) {
        $selectedOwnerId = 0;
    }
}
$autoOpenPetForm = $selectedOwnerId > 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
$groomingVisitsByPet = [];
if ($isGroomerAccount) {
    if ($groomerId > 0) {
        $rowsStatement = $pdo->prepare(
            "SELECT p.pet_id, p.owner_id, p.pet_name, p.species, p.breed, p.birth_date, p.gender,
                    p.photo_updated_at, o.full_name AS owner_name,
                    COUNT(DISTINCT a.appointment_id) AS visit_count,
                    MAX(a.appointment_date) AS last_visit
             FROM Appointments a
             JOIN Pets p ON p.pet_id = a.pet_id
             JOIN PetOwners o ON o.owner_id = p.owner_id
             JOIN AppointmentServices aps ON aps.appointment_id = a.appointment_id
             JOIN Services s ON s.service_id = aps.service_id
             WHERE aps.groomer_id = ?
               AND s.service_category = 'Grooming & Hygiene'
               AND a.archived_at IS NULL AND p.archived_at IS NULL
               AND a.pickup_ready_at IS NOT NULL
               AND a.status NOT IN ('Cancelled', 'Rejected')
             GROUP BY p.pet_id, p.owner_id, p.pet_name, p.species, p.breed, p.birth_date, p.gender,
                      p.photo_updated_at, o.full_name
             ORDER BY last_visit DESC, p.pet_name"
        );
        $rowsStatement->execute([$groomerId]);
        $rows = $rowsStatement->fetchAll();

        $visitsStatement = $pdo->prepare(
            "SELECT a.appointment_id, a.pet_id, a.appointment_date, a.status,
                    a.pickup_ready_at, a.picked_up_at,
                    GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.service_name SEPARATOR ', ') AS service_names
             FROM Appointments a
             JOIN Pets p ON p.pet_id = a.pet_id AND p.archived_at IS NULL
             JOIN AppointmentServices aps ON aps.appointment_id = a.appointment_id
             JOIN Services s ON s.service_id = aps.service_id
             WHERE aps.groomer_id = ?
               AND s.service_category = 'Grooming & Hygiene'
               AND a.archived_at IS NULL
               AND a.pickup_ready_at IS NOT NULL
               AND a.status NOT IN ('Cancelled', 'Rejected')
             GROUP BY a.appointment_id, a.pet_id, a.appointment_date, a.status,
                      a.pickup_ready_at, a.picked_up_at
             ORDER BY a.appointment_date DESC, a.appointment_id DESC"
        );
        $visitsStatement->execute([$groomerId]);
        foreach ($visitsStatement->fetchAll() as $visit) {
            $groomingVisitsByPet[(int) $visit['pet_id']][] = $visit;
        }
    } else {
        $rows = [];
    }
} else {
    $rowsStatement = $pdo->query(
        'SELECT p.pet_id, p.owner_id, p.pet_name, p.species, p.breed, p.birth_date, p.gender,
                p.photo_updated_at, o.full_name AS owner_name
         FROM Pets p
         JOIN PetOwners o ON o.owner_id = p.owner_id
         WHERE p.archived_at IS NULL
         ORDER BY p.pet_name'
    );
    $rows = $rowsStatement->fetchAll();
}
$requestedPetHasHistory = $isGroomerAccount
    && $requestedPetId > 0
    && in_array($requestedPetId, array_map('intval', array_column($rows, 'pet_id')), true);

$petAge = static function (?string $birthDate): string {
    if (!$birthDate) {
        return 'Age not listed';
    }

    try {
        $age = (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable('today'));
    } catch (Exception $exception) {
        return 'Age not listed';
    }

    if ($age->y > 0) {
        return $age->y . ($age->y === 1 ? ' year' : ' years');
    }

    return max(0, $age->m) . ($age->m === 1 ? ' month' : ' months');
};
$petDate = static function (?string $value, string $format = 'M j, Y'): string {
    if ($value === null || $value === '') {
        return '—';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date($format, $timestamp);
};

role_render(
    'staff',
    ['owners', 'pets'],
    $isGroomerAccount ? 'Pet Records' : 'Clients & Pets',
    $isGroomerAccount ? 'Pets from your completed grooming appointments' : 'Manage pet owners and their pets in one workspace',
    function (array $context) use ($isGroomerAccount, $groomingVisitsByPet, $owners, $rows, $error, $success, $selectedOwnerId, $autoOpenPetForm, $petAge, $petDate, $requestedPetId, $requestedPetHasHistory): void {
    if ($isGroomerAccount) {
        if ($error !== '') {
            echo '<div class="notice error" role="alert">' . e($error) . '</div>';
        }
        if ($requestedPetId > 0 && !$requestedPetHasHistory) {
            echo '<div class="notice">No completed grooming history is available for this pet yet.</div>';
        }
        ?>
        <section class="panel gp-panel">
          <div class="panel-header">
            <div><h2>Your patients</h2><p class="hint">Open a pet to review its grooming history.</p></div>
            <span class="data-count"><?= count($rows) ?> patients</span>
          </div>
          <div class="panel-body gp-panel-body">
            <div class="gp-toolbar">
              <label class="field gp-search">Search patients
                <input type="search" id="gp-search" placeholder="Pet, owner or species" autocomplete="off">
              </label>
              <span class="table-search-count" id="gp-search-count" aria-live="polite"></span>
            </div>
            <?php if ($rows): ?>
              <div class="gp-grid" id="gp-patient-grid">
                <?php foreach ($rows as $patient): ?>
                  <?php
                    $petId = (int) $patient['pet_id'];
                    $photo = pet_photo_url($patient['photo_updated_at'] ?? null, $petId);
                    $initial = mb_strtoupper(mb_substr((string) $patient['pet_name'], 0, 1));
                    $petDescription = trim(
                        (string) $patient['species']
                        . (!empty($patient['breed']) ? ' · ' . $patient['breed'] : '')
                        . (!empty($patient['gender']) ? ' · ' . $patient['gender'] : '')
                    );
                    $searchText = mb_strtolower(
                        (string) $patient['pet_name'] . ' ' . (string) $patient['owner_name'] . ' '
                        . (string) $patient['species'] . ' ' . (string) $patient['breed']
                    );
                    $patientVisits = $groomingVisitsByPet[$petId] ?? [];
                  ?>
                  <article class="gp-card" data-patient-card data-search="<?= e($searchText) ?>">
                    <div class="gp-card-head">
                      <span class="gp-photo" aria-hidden="true"><?php if ($photo !== ''): ?><img src="<?= e($photo) ?>" alt="" loading="lazy" decoding="async"><?php else: ?><?= e($initial) ?><?php endif; ?></span>
                      <div><h3><?= e($patient['pet_name']) ?></h3><span class="gp-subtitle"><?= e($petDescription) ?></span></div>
                    </div>
                    <dl class="gp-card-facts">
                      <div><dt>Owner</dt><dd><?= e($patient['owner_name']) ?></dd></div>
                      <div><dt>Age</dt><dd><?= e($petAge($patient['birth_date'] ?? null)) ?></dd></div>
                      <div><dt>Visits</dt><dd><?= (int) $patient['visit_count'] ?></dd></div>
                      <div><dt>Last visit</dt><dd><?= e($petDate($patient['last_visit'] ?? null)) ?></dd></div>
                    </dl>
                    <div class="gp-card-actions">
                      <button class="button small gp-open-patient" type="button"
                        data-pet="<?= $petId ?>"
                        data-pet-name="<?= e($patient['pet_name']) ?>"
                        data-owner="<?= e($patient['owner_name']) ?>"
                        data-photo="<?= e($photo) ?>"
                        data-description="<?= e($petDescription) ?>">View records</button>
                    </div>
                    <template id="gp-patient-<?= $petId ?>">
                      <section class="gp-records">
                        <div class="gp-section-title">
                          <h3>Grooming history</h3>
                          <span class="data-count"><?= count($patientVisits) ?> visits</span>
                        </div>
                        <div class="gp-visit-list">
                          <?php foreach ($patientVisits as $visit): ?>
                            <?php $visitStatus = !empty($visit['picked_up_at']) ? 'Complete' : 'Ready for pickup'; ?>
                            <article class="gp-visit">
                              <div class="gp-visit-top">
                                <strong><?= e($petDate($visit['appointment_date'], 'D, M j, Y · g:i A')) ?></strong>
                                <span class="status <?= e(role_status_class($visitStatus)) ?>"><?= e($visitStatus) ?></span>
                              </div>
                              <dl class="gp-visit-facts">
                                <div><dt>Services</dt><dd><?= e($visit['service_names'] ?: 'Grooming service') ?></dd></div>
                                <?php if (!empty($visit['picked_up_at'])): ?>
                                  <div><dt>Picked up</dt><dd><?= e($petDate($visit['picked_up_at'], 'M j, Y · g:i A')) ?></dd></div>
                                <?php endif; ?>
                              </dl>
                            </article>
                          <?php endforeach; ?>
                        </div>
                      </section>
                    </template>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="gp-empty"><strong>No completed grooming patients yet</strong>Pets will appear here after you mark an assigned grooming appointment ready for pickup.</div>
            <?php endif; ?>
            <div class="gp-empty" id="gp-no-results" hidden><strong>No matching patients</strong>Try a different pet, owner or species name.</div>
          </div>
        </section>

        <dialog class="gp-modal" id="gp-patient-dialog" aria-labelledby="gp-dialog-title">
          <div class="gp-modal-head">
            <div class="gp-card-head">
              <span class="gp-photo" id="gp-dialog-photo" aria-hidden="true"></span>
              <div><h2 id="gp-dialog-title">Pet records</h2><p id="gp-dialog-subtitle"></p></div>
            </div>
            <button class="button secondary small" type="button" data-close-gp-patient>Close</button>
          </div>
          <div class="gp-modal-body" id="gp-patient-content"></div>
        </dialog>

        <style>
          .gp-panel-body{min-height:0}
          .gp-toolbar{display:flex;align-items:end;justify-content:space-between;gap:14px;margin:0 0 16px}
          .gp-search{width:min(420px,100%);margin:0}
          .gp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,270px),1fr));gap:16px}
          .gp-card{min-width:0;padding:16px;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:0 6px 20px rgba(13,54,86,.06)}
          .gp-card-head{display:flex;align-items:center;gap:13px;min-width:0}
          .gp-photo{width:58px;height:58px;flex:0 0 58px;display:grid;place-items:center;overflow:hidden;border-radius:16px;background:var(--blue-50);color:var(--blue-700);font-size:1.15rem;font-weight:800}
          .gp-photo img{width:100%;height:100%;object-fit:cover}
          .gp-card h3{margin:0;color:var(--blue-900);font-size:1.03rem}
          .gp-subtitle{display:block;margin-top:3px;color:var(--muted);font-size:.82rem}
          .gp-card-facts{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin:14px 0}
          .gp-card-facts div{min-width:0;padding:9px 10px;border-radius:11px;background:#f5f9fc}
          .gp-card-facts dt,.gp-visit-facts dt{color:var(--muted);font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
          .gp-card-facts dd{margin:4px 0 0;color:var(--blue-900);font-size:.84rem;font-weight:700;overflow-wrap:anywhere}
          .gp-card-actions{display:flex;gap:8px}.gp-card-actions .button{flex:1}
          .gp-modal{width:min(760px,calc(100vw - 28px));max-width:760px;max-height:min(88vh,900px);padding:0;border:1px solid var(--line);border-radius:18px;box-shadow:0 22px 70px rgba(3,34,57,.28);color:var(--ink)}
          .gp-modal::backdrop{background:rgba(6,30,49,.55);backdrop-filter:blur(2px)}
          .gp-modal-head{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 22px;background:#fff;border-bottom:1px solid var(--line)}
          .gp-modal-head h2{margin:0;color:var(--blue-900);font-size:1.15rem}
          .gp-modal-head p{margin:4px 0 0;color:var(--muted);font-size:.82rem}
          .gp-modal-body{padding:18px 22px 22px}
          .gp-section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 12px}
          .gp-section-title h3{margin:0;color:var(--blue-900);font-size:1rem}
          .gp-visit-list{display:grid;gap:10px}
          .gp-visit{padding:14px;border:1px solid var(--line);border-radius:12px;background:#fff}
          .gp-visit-top{display:flex;align-items:center;justify-content:space-between;gap:12px}
          .gp-visit-top strong{color:var(--blue-900)}
          .gp-visit-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 16px;margin:12px 0 0}
          .gp-visit-facts dd{margin:3px 0 0;overflow-wrap:anywhere}
          .gp-empty{padding:26px 18px;border:1px dashed #c5dbea;border-radius:12px;text-align:center;color:var(--muted)}
          .gp-empty strong{display:block;margin-bottom:4px;color:var(--blue-900)}
          @media(max-width:680px){.gp-toolbar{align-items:stretch;flex-direction:column}.gp-modal-head,.gp-modal-body{padding-left:15px;padding-right:15px}.gp-visit-facts{grid-template-columns:1fr}}
        </style>
        <script>
          (function () {
            const dialog = document.getElementById('gp-patient-dialog');
            const content = document.getElementById('gp-patient-content');
            const search = document.getElementById('gp-search');
            const cards = Array.from(document.querySelectorAll('[data-patient-card]'));
            const noResults = document.getElementById('gp-no-results');
            const searchCount = document.getElementById('gp-search-count');
            const requestedPetId = <?= $requestedPetHasHistory ? $requestedPetId : 0 ?>;

            function applySearch() {
              const query = search.value.trim().toLocaleLowerCase();
              let visible = 0;
              cards.forEach(function (card) {
                const matches = !query || card.dataset.search.indexOf(query) !== -1;
                card.hidden = !matches;
                if (matches) visible++;
              });
              noResults.hidden = visible !== 0 || cards.length === 0;
              searchCount.textContent = query ? visible + ' matching patients' : '';
            }

            search.addEventListener('input', applySearch);
            document.addEventListener('click', function (event) {
              const target = event.target;
              const openButton = target.closest('.gp-open-patient');
              if (openButton) {
                const template = document.getElementById('gp-patient-' + openButton.dataset.pet);
                if (!template) {
                  console.error('Grooming history template is missing for pet ' + openButton.dataset.pet);
                  return;
                }
                document.getElementById('gp-dialog-title').textContent = openButton.dataset.petName;
                document.getElementById('gp-dialog-subtitle').textContent =
                  [openButton.dataset.description, 'Owner: ' + openButton.dataset.owner].filter(Boolean).join(' · ');
                const photo = document.getElementById('gp-dialog-photo');
                photo.replaceChildren();
                if (openButton.dataset.photo) {
                  const image = document.createElement('img');
                  image.src = openButton.dataset.photo;
                  image.alt = '';
                  photo.appendChild(image);
                } else {
                  photo.textContent = openButton.dataset.petName.trim().charAt(0).toUpperCase();
                }
                content.replaceChildren(template.content.cloneNode(true));
                if (!dialog.open) dialog.showModal();
                return;
              }
              if (target.closest('[data-close-gp-patient]')) dialog.close();
            });

            if (requestedPetId > 0) {
              const historyButton = document.querySelector('.gp-open-patient[data-pet="' + requestedPetId + '"]');
              if (historyButton) historyButton.click();
            }
          })();
        </script>
        <?php
        return;
    }

    if ($error !== '') {
        echo '<div class="notice error" role="alert">' . e($error) . '</div>';
    }
    if ($success !== '') {
        echo '<div class="notice success" role="status">' . e($success) . '</div>';
    }
    ?>
    <nav class="record-tabs" aria-label="Client and pet records">
      <a class="record-tab" href="owners.php">Pet owners</a>
      <a class="record-tab" href="pets.php" aria-current="page">Pets</a>
    </nav>
    <section class="panel pet-register-panel">
      <div class="panel-header"><h2>Pet register</h2><span class="data-count"><?= count($rows) ?> pets</span></div>
      <div class="panel-body pet-panel-body">
        <div class="pet-toolbar">
          <div class="pet-search-row" role="search">
            <label class="field" for="pet-search">Search pets
              <input id="pet-search" type="search" maxlength="100" autocomplete="off" placeholder="Search pet, owner or species">
            </label>
            <span class="pet-search-count" id="pet-search-count" aria-live="polite"></span>
          </div>
          <button class="button" type="button" id="add-pet">+ Register pet</button>
        </div>
        <div class="table-wrap pet-table-wrap data-table-card">
          <table class="records-table pet-register-table">
            <colgroup><col style="width:20%"><col style="width:19%"><col style="width:22%"><col style="width:9%"><col style="width:14%"><col></colgroup>
            <thead><tr><th>Pet</th><th>Owner</th><th>Species / Breed</th><th>Sex</th><th>Birth date</th><th>Action</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $index => $row): ?>
                <?php
                  $photoUrl = pet_photo_url($row['photo_updated_at'] ?? null, (int) $row['pet_id']);
                  $searchText = mb_strtolower($row['pet_name'] . ' ' . $row['owner_name'] . ' ' . $row['species'] . ' ' . ($row['breed'] ?? ''));
                ?>
                <tr<?= $index % 2 ? ' class="alt"' : '' ?> data-pet-row data-search="<?= e($searchText) ?>">
                  <td data-label="Pet" class="pet-name-cell">
                    <?php if ($photoUrl !== ''): ?>
                      <img class="pet-thumb" src="<?= e($photoUrl) ?>" alt="Photo of <?= e($row['pet_name']) ?>" loading="lazy">
                    <?php else: ?>
                      <span class="pet-thumb pet-thumb-empty" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($row['pet_name'], 0, 1))) ?></span>
                    <?php endif; ?>
                    <strong><?= e($row['pet_name']) ?></strong>
                  </td>
                  <td data-label="Owner"><?= e($row['owner_name']) ?></td>
                  <td data-label="Species / Breed"><?= e($row['species']) ?><?= $row['breed'] ? ' · ' . e($row['breed']) : '' ?></td>
                  <td data-label="Sex"><?= e($row['gender'] ?: 'Not set') ?></td>
                  <td data-label="Birth date"><?= e($row['birth_date'] ?: 'Not set') ?></td>
                  <td data-label="Action">
                    <button class="button small secondary edit-pet" type="button"
                      data-id="<?= (int) $row['pet_id'] ?>"
                      data-owner="<?= (int) $row['owner_id'] ?>"
                      data-name="<?= e($row['pet_name']) ?>"
                      data-species="<?= e($row['species']) ?>"
                      data-breed="<?= e($row['breed'] ?: '') ?>"
                      data-gender="<?= e($row['gender'] ?: '') ?>"
                      data-birth="<?= e($row['birth_date'] ?: '') ?>"
                      data-photo="<?= e($photoUrl) ?>">Edit</button>
                  </td>
                </tr>
              <?php endforeach; ?>
              <tr id="pet-search-empty"<?= $rows ? ' hidden' : '' ?>>
                <td colspan="6">
                  <div class="pet-empty-state">
                    <strong><?= $rows ? 'No pets match your search' : 'No pets are registered' ?></strong>
                    <small><?= $rows ? 'Try another pet name, owner or species.' : 'Register a pet to connect appointments and care records.' ?></small>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <dialog id="pet-dialog" aria-labelledby="pet-title">
      <div class="dialog-head">
        <h2 id="pet-title">Register pet</h2>
        <button class="dialog-close" type="button" aria-label="Close dialog">×</button>
      </div>
      <form class="dialog-form" id="pet-form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="pet_id" id="pet-id" value="0">
        <div class="form-grid">
          <label class="field full">Owner
            <select name="owner_id" id="pet-owner" data-initial-owner="<?= $selectedOwnerId ?>" data-auto-open="<?= $autoOpenPetForm ? '1' : '0' ?>" required>
              <option value="">Choose owner</option>
              <?php foreach ($owners as $owner): ?>
                <option value="<?= (int) $owner['owner_id'] ?>"><?= e($owner['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field">Pet name<input name="pet_name" id="pet-name" required maxlength="50"></label>
          <label class="field">Species<input name="species" id="pet-species" required maxlength="50"></label>
          <label class="field">Breed<input name="breed" id="pet-breed" maxlength="50"></label>
          <label class="field">Sex<select name="gender" id="pet-gender"><option value="">Not sure</option><option>Male</option><option>Female</option></select></label>
          <label class="field full">Birth date<input type="date" name="birth_date" id="pet-birth"></label>
          <div class="field full pet-photo-field">
            <span>Pet photo</span>
            <div class="pet-photo-preview">
              <img id="pet-photo-preview" alt="Pet photo preview" hidden>
              <span id="pet-photo-placeholder">No photo selected</span>
            </div>
            <input type="file" name="pet_photo" id="pet-photo-input" accept="image/jpeg,image/png,image/webp">
            <span class="hint">JPG, PNG or WebP; maximum 2 MB. Photos are stored in the clinic database.</span>
            <input type="hidden" name="remove_photo" value="0" id="remove-photo">
            <button class="button danger small photo-remove-button" id="remove-photo-button" type="button" hidden>Remove current photo</button>
          </div>
        </div>
        <div class="form-actions">
          <button class="button secondary pet-cancel" type="button">Cancel</button>
          <button class="button" type="submit">Save pet</button>
        </div>
      </form>
    </dialog>
    <style>
      .pet-name-cell{min-width:160px;display:table-cell}
      .pet-thumb{width:42px;height:42px;margin-right:9px;display:inline-grid;place-items:center;vertical-align:middle;object-fit:cover;border:1px solid #d7e8f5;border-radius:50%}
      .pet-thumb-empty{color:#015b8c;background:#eaf3fa;font-weight:700}
      .pet-name-cell strong{vertical-align:middle}
      .pet-panel-body{min-height:0;display:flex;flex-direction:column}
      .pet-toolbar{flex:none;margin:0 0 14px;display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:12px}
      .pet-search-row{min-width:0;width:auto;flex:1 1 320px;display:flex;align-items:flex-end;flex-wrap:wrap;gap:12px}
      .pet-search-row .field{max-width:none;flex:1 1 250px}
      .pet-search-count{min-height:41px;display:flex;align-items:center;color:var(--muted);font-size:.78rem;white-space:nowrap}
      .pet-toolbar>.button{flex:none;min-height:41px}
      .pet-table-wrap{flex:none;min-height:0;max-height:none;overflow-x:auto;overflow-y:hidden;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#91abc0 #edf3f8}
      .pet-register-table{width:100%;min-width:900px;table-layout:fixed}
      .pet-register-table th{position:sticky;top:0;z-index:1}
      .pet-register-table th:nth-child(1){width:21%}.pet-register-table th:nth-child(2){width:19%}
      .pet-register-table th:nth-child(3){width:23%}.pet-register-table th:nth-child(4){width:11%}
      .pet-register-table th:nth-child(5){width:14%}.pet-register-table th:nth-child(6){width:12%}
      .pet-empty-state{min-height:300px;padding:18px;display:grid;place-content:center;gap:5px;color:var(--muted);text-align:center}
      .pet-empty-state strong{color:#4d6f8a;font-size:.95rem}.pet-empty-state small{font-size:.78rem}
      .pet-table-wrap::-webkit-scrollbar{width:9px;height:9px}
      .pet-table-wrap::-webkit-scrollbar-track{background:#edf3f8;border-radius:8px}
      .pet-table-wrap::-webkit-scrollbar-thumb{background:#91abc0;border:2px solid #edf3f8;border-radius:8px}
      .pet-photo-preview{width:112px;height:112px;display:grid;place-items:center;overflow:hidden;color:#42637e;background:#f4f9fd;border:1px dashed #8fbadb;border-radius:12px;font-size:.78rem;text-align:center}
      .pet-photo-preview img{width:100%;height:100%;object-fit:cover}
      .photo-remove-button{justify-self:start}
      .photo-remove-button[hidden]{display:none!important}
      .dialog-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
      .dialog-close{width:34px;height:34px;padding:0;color:#fff;background:transparent;border:1px solid rgba(255,255,255,.55);border-radius:8px;font-size:1.35rem;line-height:1;cursor:pointer}
      .dialog-close:hover{background:rgba(255,255,255,.15)}
      .form-grid .full{grid-column:1/-1}
      @media(max-width:600px){.pet-panel-body{height:auto;padding:12px}.pet-search-row .field{flex-basis:100%}.pet-search-count{min-height:0}.pet-toolbar>.button{align-self:flex-end}.pet-register-table{min-width:850px}}
    </style>
    <script>
      (function () {
        var dialog = document.getElementById('pet-dialog');
        var form = document.getElementById('pet-form');
        var preview = document.getElementById('pet-photo-preview');
        var placeholder = document.getElementById('pet-photo-placeholder');
        var photoInput = document.getElementById('pet-photo-input');
        var removePhotoButton = document.getElementById('remove-photo-button');
        var removePhoto = document.getElementById('remove-photo');
        var ownerSelect = document.getElementById('pet-owner');
        var initialOwnerId = ownerSelect.dataset.initialOwner;
        var autoOpenPetForm = ownerSelect.dataset.autoOpen === '1';
        var objectUrl = '';
        var savedPhotoUrl = '';

        function setPreview(url) {
          preview.hidden = !url;
          placeholder.hidden = !!url;
          placeholder.textContent = removePhoto.value === '1' ? 'Photo will be removed when you save.' : 'No photo selected';
          if (url) preview.src = url;
          else preview.removeAttribute('src');
        }
        function updateRemovePhotoButton() {
          var hasNewPhoto = !!(photoInput.files && photoInput.files[0]);
          var isRemoving = removePhoto.value === '1';
          removePhotoButton.hidden = !savedPhotoUrl || hasNewPhoto;
          removePhotoButton.textContent = isRemoving ? 'Keep current photo' : 'Remove current photo';
          removePhotoButton.classList.toggle('danger', !isRemoving);
          removePhotoButton.classList.toggle('secondary', isRemoving);
        }
        function closeDialog() {
          if (form.dataset.dirty === '1' && !window.confirm('Discard unsaved pet changes?')) return;
          dialog.close();
        }
        function resetForm() {
          form.reset();
          form.dataset.dirty = '0';
          document.getElementById('pet-id').value = '0';
          ownerSelect.value = initialOwnerId;
          document.getElementById('pet-title').textContent = 'Register pet';
          removePhoto.value = '0';
          savedPhotoUrl = '';
          if (objectUrl) URL.revokeObjectURL(objectUrl);
          objectUrl = '';
          setPreview('');
          updateRemovePhotoButton();
        }
        document.getElementById('add-pet').addEventListener('click', function () {
          resetForm();
          dialog.showModal();
            }
        );
        document.querySelectorAll('.edit-pet').forEach(function (button) {
          button.addEventListener('click', function () {
            resetForm();
            document.getElementById('pet-title').textContent = 'Edit pet';
            document.getElementById('pet-id').value = button.dataset.id;
            document.getElementById('pet-owner').value = button.dataset.owner;
            document.getElementById('pet-name').value = button.dataset.name;
            document.getElementById('pet-species').value = button.dataset.species;
            document.getElementById('pet-breed').value = button.dataset.breed;
            document.getElementById('pet-gender').value = button.dataset.gender;
            document.getElementById('pet-birth').value = button.dataset.birth;
            savedPhotoUrl = button.dataset.photo;
            removePhoto.value = '0';
            setPreview(savedPhotoUrl);
            updateRemovePhotoButton();
            form.dataset.dirty = '0';
            dialog.showModal();
          });
        });
        photoInput.addEventListener('change', function () {
          if (objectUrl) URL.revokeObjectURL(objectUrl);
          objectUrl = photoInput.files && photoInput.files[0] ? URL.createObjectURL(photoInput.files[0]) : '';
          removePhoto.value = '0';
          setPreview(objectUrl || savedPhotoUrl);
          updateRemovePhotoButton();
        });
        removePhotoButton.addEventListener('click', function () {
          var isRemoving = removePhoto.value !== '1';
          removePhoto.value = isRemoving ? '1' : '0';
          form.dataset.dirty = '1';
          if (isRemoving && objectUrl) URL.revokeObjectURL(objectUrl);
          if (isRemoving) {
            objectUrl = '';
            photoInput.value = '';
          }
          setPreview(isRemoving ? '' : savedPhotoUrl);
          updateRemovePhotoButton();
        });
        form.addEventListener('input', function () { form.dataset.dirty = '1'; });
        form.addEventListener('change', function () { form.dataset.dirty = '1'; });
        form.addEventListener('submit', function () { form.dataset.dirty = '0'; });
        dialog.querySelector('.dialog-close').addEventListener('click', closeDialog);
        dialog.querySelector('.pet-cancel').addEventListener('click', closeDialog);
        dialog.addEventListener('cancel', function (event) {
          if (form.dataset.dirty === '1') {
            event.preventDefault();
            closeDialog();
          }
        });
        var search = document.getElementById('pet-search');
        var rows = Array.prototype.slice.call(document.querySelectorAll('[data-pet-row]'));
        var empty = document.getElementById('pet-search-empty');
        var searchCount = document.getElementById('pet-search-count');
        function applyPetSearch() {
          var query = search.value.trim().toLocaleLowerCase();
          var visible = 0;
          rows.forEach(function (row) {
            var matches = !query || row.dataset.search.indexOf(query) !== -1;
            row.hidden = !matches;
            row.classList.toggle('alt', matches && visible % 2 === 1);
            if (matches) visible++;
          });
          searchCount.textContent = visible + ' of ' + rows.length + ' shown';
          if (empty) empty.hidden = rows.length > 0 && (visible > 0 || query === '');
        }
        search.addEventListener('input', applyPetSearch);
        applyPetSearch();
        if (autoOpenPetForm) {
          resetForm();
          dialog.showModal();
        }
      })();
    </script>
    <?php
});
