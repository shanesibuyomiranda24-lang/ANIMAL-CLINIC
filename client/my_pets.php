<?php
// client/my_pets.php - list, add and edit the owner's pets
require_once __DIR__ . '/client_layout.php';
require_once __DIR__ . '/../includes/audit_log.php';
$c = client_boot();
$pdo = $c['pdo'];
$error = '';
$old = [];
$returnToBooking = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    ? posted_string('return_to')
    : (string) ($_GET['return_to'] ?? '')) === 'booking';

if (!$c['dbError'] && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && posted_string('action') === 'archive_pet') {
    $petId = filter_var(posted_string('pet_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Please try again.';
    } elseif ($petId === false) {
        $error = 'Choose a valid pet profile to archive.';
    } else {
        try {
            $pdo->beginTransaction();
            $petCheck = $pdo->prepare('SELECT pet_id, archived_at FROM Pets WHERE pet_id = ? AND owner_id = ? FOR UPDATE');
            $petCheck->execute([(int) $petId, (int) $c['ownerId']]);
            $pet = $petCheck->fetch();
            if (!$pet || $pet['archived_at'] !== null) {
                throw new InvalidArgumentException('That pet could not be found in your account.');
            }

            $activeAppointmentCheck = $pdo->prepare(
                "SELECT appointment_id FROM Appointments
                 WHERE pet_id = ? AND archived_at IS NULL
                   AND (
                       status NOT IN ('Complete','Cancelled','Rejected')
                       OR (pickup_ready_at IS NOT NULL AND picked_up_at IS NULL)
                   )
                 FOR UPDATE"
            );
            $activeAppointmentCheck->execute([(int) $petId]);
            if ($activeAppointmentCheck->fetchColumn()) {
                throw new InvalidArgumentException('This pet has an active appointment. Resolve or cancel it before archiving the profile.');
            }

            $pdo->prepare(
                'UPDATE Pets SET archived_at = NOW(), archived_by_user_id = ?,
                    restored_at = NULL, restored_by_user_id = NULL
                 WHERE pet_id = ? AND owner_id = ? AND archived_at IS NULL'
            )->execute([(int) $_SESSION['user_id'], (int) $petId, (int) $c['ownerId']]);
            audit_log_record(
                $pdo,
                (int) $_SESSION['user_id'],
                'pet.archived',
                'pet',
                (int) $petId,
                'Pet owner archived pet profile'
            );
            $pdo->commit();
            header('Location: my_pets.php?ok=petarchived');
            exit;
        } catch (InvalidArgumentException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $ex->getMessage();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($ex->getCode() === '23000') {
                $error = 'The pet profile could not be archived because a related record is being updated.';
            } else {
                error_log('archive pet: ' . $ex->getMessage());
                $error = 'The pet profile could not be archived. Please try again.';
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('archive pet: ' . $ex->getMessage());
            $error = 'The pet profile could not be archived. Please try again.';
        }
    }
}

if (!$c['dbError'] && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && posted_string('action') === 'save_pet') {
    $old = $_POST;
    $petId = (int) posted_string('pet_id');
    $name = trim(preg_replace('/\s+/', ' ', posted_string('pet_name')));
    $species = posted_string('species');
    $breed = trim(posted_string('breed'));
    $gender = in_array(posted_string('gender'), ['Male', 'Female'], true) ? posted_string('gender') : null;
    $birthInput = posted_string('birth_date');
    $birthDate = $birthInput !== '' ? DateTime::createFromFormat('!Y-m-d', $birthInput) : false;
    $birth = $birthDate && $birthDate->format('Y-m-d') === $birthInput ? $birthInput : null;
    if (!csrf_valid(posted_string('csrf'))) {
        $error = 'Your session expired. Please try again.';
    } elseif ($name === '' || mb_strlen($name) > 50 || !in_array($species, SPECIES, true) || mb_strlen($breed) > 50) {
        $error = 'Enter your pet’s name and choose its species.';
    } elseif ($birthInput !== '' && !$birth) {
        $error = 'Enter a valid birth date.';
    } elseif ($birth && strtotime($birth) > time()) {
        $error = 'Birth date cannot be in the future.';
    } else {
        try {
            $photo = pet_photo_read_upload($_FILES['pet_photo'] ?? []);
            $pdo->beginTransaction();
            if ($petId > 0) {
                $current = $pdo->prepare('SELECT pet_id FROM Pets WHERE pet_id = ? AND owner_id = ? AND archived_at IS NULL FOR UPDATE');
                $current->execute([$petId, $c['ownerId']]);
                $existing = $current->fetch();
                if (!$existing) {
                    throw new InvalidArgumentException('That pet could not be found in your account.');
                }
            }
            if ($petId > 0) {
                $pdo->prepare('UPDATE Pets SET pet_name=?, species=?, breed=?, birth_date=?, gender=? WHERE pet_id=? AND owner_id=?')
                    ->execute([$name, $species, $breed ?: null, $birth, $gender, $petId, $c['ownerId']]);
            } else {
                $pdo->prepare('INSERT INTO Pets (owner_id, pet_name, species, breed, birth_date, gender) VALUES (?,?,?,?,?,?)')
                    ->execute([$c['ownerId'], $name, $species, $breed ?: null, $birth, $gender]);
                $petId = (int) $pdo->lastInsertId();
            }
            if ($photo !== null) {
                pet_photo_save($pdo, $petId, $photo);
            } elseif (posted_string('remove_photo') === '1') {
                pet_photo_save($pdo, $petId, null);
            }
            $pdo->commit();
            if ($returnToBooking) {
                header('Location: book_appointment.php?pet=' . $petId . '&ok=petsaved');
                exit;
            }
            header('Location: my_pets.php?ok=petsaved');
            exit;
        } catch (InvalidArgumentException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $ex->getMessage();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('save pet: ' . $ex->getMessage());
            $error = 'The pet could not be saved. Please try again.';
        }
    }
}

$pets = $c['dbError'] ? [] : qall(
    $pdo,
    'SELECT p.pet_id, p.owner_id, p.pet_name, p.species, p.breed, p.birth_date, p.gender, p.photo_updated_at
     FROM Pets p WHERE p.owner_id = ? AND p.archived_at IS NULL ORDER BY p.pet_name',
    [$c['ownerId']]
);
$oldPetPhoto = '';
foreach ($pets as $pet) {
    if ((int) $pet['pet_id'] === (int) ($old['pet_id'] ?? 0)) {
        $oldPetPhoto = pet_photo_url($pet['photo_updated_at'] ?? null, (int) $pet['pet_id']);
        break;
    }
}
client_header($c, 'My Pets', 'Pets registered under your account', 'pets');
?>
        <?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <?php if (($_GET['ok'] ?? '') === 'petarchived'): ?><div class="notice ok" role="status">Pet profile archived. Its appointment and medical history remains preserved.</div><?php endif; ?>
        <section class="panel">
          <div class="panel-header"><h2>Your pets</h2><span class="data-count"><?= count($pets) ?> pet<?= count($pets) === 1 ? '' : 's' ?></span></div>
          <div class="panel-body">
            <div class="pet-toolbar">
              <div><h3>Pet profiles</h3><p>Keep each pet’s care details and visit history together.</p></div>
              <button class="button" type="button" id="add-pet">+ Add a pet</button>
            </div>
            <?php if ($pets): ?>
            <?php client_search('pets-search', 'Find a pet', 'Search by name, species or breed…', 'pets-grid', 'pets-count', 'pets-no-match'); ?>
            <div class="pet-grid" id="pets-grid">
              <?php foreach ($pets as $p): $age = pet_age($p['birth_date'] ?? null); $photoUrl = pet_photo_url($p['photo_updated_at'] ?? null, (int) $p['pet_id']); ?>
                <article class="pet-card" data-search-item>
                  <div class="pet-top"><span class="pet-photo" aria-hidden="true"><?php if ($photoUrl): ?><img src="<?= e($photoUrl) ?>" alt="" loading="lazy" decoding="async"><?php else: ?><?= e(mb_strtoupper(mb_substr((string) $p['pet_name'], 0, 1))) ?><?php endif; ?></span>
                    <div><h3><?= e($p['pet_name']) ?></h3><span><?= e(trim(($p['species'] ?? '') . (!empty($p['breed']) ? ' · ' . $p['breed'] : ''))) ?></span></div></div>
                  <dl class="pet-facts">
                    <div><dt>Age</dt><dd><?= e($age ?: 'Not set') ?></dd></div>
                    <div><dt>Sex</dt><dd><?= e($p['gender'] ?? '') ?: 'Not set' ?></dd></div>
                    <div><dt>Birth date</dt><dd><?= !empty($p['birth_date']) ? e(date('M j, Y', strtotime($p['birth_date']))) : 'Not set' ?></dd></div>
                  </dl>
                  <div class="pet-actions">
                    <a class="button small" href="book_appointment.php?pet=<?= (int) $p['pet_id'] ?>">Book a visit</a>
                    <a class="button secondary small" href="vaccinations.php?pet=<?= (int) $p['pet_id'] ?>">Vaccinations</a>
                    <button class="button secondary small edit-pet" type="button"
                      data-id="<?= (int) $p['pet_id'] ?>" data-name="<?= e($p['pet_name']) ?>" data-species="<?= e($p['species'] ?? '') ?>"
                      data-breed="<?= e($p['breed'] ?? '') ?>" data-gender="<?= e($p['gender'] ?? '') ?>" data-birth="<?= e($p['birth_date'] ?? '') ?>" data-photo="<?= e($photoUrl) ?>">Edit</button>
                    <button class="button danger small delete-pet" type="button"
                      data-id="<?= (int) $p['pet_id'] ?>" data-name="<?= e($p['pet_name']) ?>">Archive</button>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
            <div class="empty" id="pets-no-match" hidden><strong>No pets match your search</strong>Try a different name, species or breed.</div>
            <?php else: ?>
              <div class="empty"><strong>No pets registered yet</strong>Add a profile to start organizing your pet’s care.</div>
            <?php endif; ?>
          </div>
        </section>

        <dialog id="pet-dialog" aria-labelledby="pd-title">
          <div class="panel-header" style="border-radius:0"><h2 id="pd-title">Add a pet</h2><button class="button ghost small" type="button" id="pd-close" aria-label="Close">✕</button></div>
          <form class="dlg-form" method="post" action="my_pets.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_pet"><input type="hidden" name="pet_id" id="pd-id" value="0"><input type="hidden" name="remove_photo" value="0" id="pd-remove">
            <?php if ($returnToBooking): ?><input type="hidden" name="return_to" value="booking"><?php endif; ?>
            <div class="form-grid">
              <label class="field">Pet name<input name="pet_name" id="pd-name" required maxlength="50" placeholder="e.g. Bantay"></label>
              <label class="field">Species<select name="species" id="pd-species" required><option value="">Choose…</option><?php foreach (SPECIES as $sp): ?><option><?= e($sp) ?></option><?php endforeach; ?></select></label>
              <label class="field">Breed (optional)<input name="breed" id="pd-breed" maxlength="50" placeholder="e.g. Aspin, Shih Tzu"></label>
              <label class="field">Sex<select name="gender" id="pd-gender"><option value="">Not sure</option><option>Male</option><option>Female</option></select></label>
              <label class="field full">Birth date (or best guess)<input type="date" name="birth_date" id="pd-birth" max="<?= date('Y-m-d') ?>"></label>
              <div class="field full">
                <span>Pet photo (optional)</span>
                <div class="photo-crop-layout">
                  <div class="photo-crop-viewport" id="photo-crop-viewport" tabindex="0" role="group" aria-label="Photo crop area. Drag to reposition or use arrow keys for fine adjustments.">
                    <img id="pd-photo-preview" alt="" draggable="false" hidden>
                    <span class="photo-crop-placeholder" id="pd-photo-placeholder">Choose a photo to preview its square crop.</span>
                  </div>
                  <div class="photo-crop-controls">
                    <p class="photo-crop-help" id="pd-crop-help">Choose a photo, then drag and zoom to frame it.</p>
                    <label class="field">Zoom<input type="range" id="pd-photo-zoom" min="1" max="3" step="0.01" value="1" disabled></label>
                    <button class="button secondary small" type="button" id="pd-photo-reset" disabled>Reset crop</button>
                    <button class="button danger small photo-remove-button" type="button" id="pd-remove-wrap" hidden>Remove current photo</button>
                    <span class="upload-hint" id="pd-photo-hint">JPG, PNG or WebP. Your crop is optimized before upload.</span>
                  </div>
                </div>
                <label class="field" style="margin-top:12px">Choose a photo<input type="file" name="pet_photo" id="pd-photo" accept="image/jpeg,image/png,image/webp"></label>
              </div>
            </div>
            <p class="hint">We use the birth date to work out your pet’s age and vaccine schedule.</p>
            <button class="button" id="pd-save" type="submit">Save pet</button>
          </form>
        </dialog>
        <dialog id="delete-dialog" aria-labelledby="delete-title">
          <div class="panel-header" style="border-radius:0"><h2 id="delete-title">Archive pet profile</h2></div>
          <form class="dlg-form" id="delete-form" method="post" action="my_pets.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="archive_pet">
            <input type="hidden" name="pet_id" id="delete-pet-id">
            <div class="delete-dialog-content">
              <p>Archive <strong id="delete-pet-name"></strong>’s profile? It will be hidden from active pet lists, but the clinic administrator can restore it.</p>
              <p>Medical, vaccination, payment, and appointment history will remain saved. Active appointments must be resolved first.</p>
              <div class="delete-dialog-actions">
                <button class="button secondary" type="button" id="delete-cancel">Keep profile</button>
                <button class="button danger" type="submit">Archive profile</button>
              </div>
            </div>
          </form>
        </dialog>
        <script>
        (function(){
          var d=document.getElementById('pet-dialog'),$=function(i){return document.getElementById(i)};
          var previewUrl='',savedPhoto='',selectedFile=null,cropImage=null,cropBaseScale=1,cropZoom=1,cropLeft=0,cropTop=0,cropWidth=0,cropHeight=0;
          var optimizingPhoto=false,photoPrepared=false,photoJob=0,dragState=null,returnToBooking=<?= $returnToBooking ? 'true' : 'false' ?>;
          var photoInput=$('pd-photo'),photoPreview=$('pd-photo-preview'),photoHint=$('pd-photo-hint'),petForm=d.querySelector('form');
          var removePhotoInput=$('pd-remove'),removePhotoButton=$('pd-remove-wrap');
          var cropViewport=$('photo-crop-viewport'),zoomInput=$('pd-photo-zoom'),resetCropButton=$('pd-photo-reset');
          function setPreview(url){
            var placeholder=$('pd-photo-placeholder');
            photoPreview.hidden=!url;placeholder.hidden=!!url;photoPreview.src=url||'';
            var removing=removePhotoInput.value==='1';
            placeholder.textContent=removing?'Photo will be removed when you save.':'Choose a photo to preview its square crop.';
            removePhotoButton.hidden=!savedPhoto||!!selectedFile;
            removePhotoButton.textContent=removing?'Keep current photo':'Remove current photo';
            removePhotoButton.classList.toggle('danger',!removing);
            removePhotoButton.classList.toggle('secondary',removing);
            if(!selectedFile){
              photoPreview.style.position='static';photoPreview.style.width='100%';photoPreview.style.height='100%';
              photoPreview.style.maxWidth='100%';photoPreview.style.objectFit='cover';
            }
          }
          function constrainCrop(){
            var size=cropViewport.clientWidth;
            cropWidth=cropImage.naturalWidth*cropBaseScale*cropZoom;
            cropHeight=cropImage.naturalHeight*cropBaseScale*cropZoom;
            cropLeft=Math.min(0,Math.max(size-cropWidth,cropLeft));
            cropTop=Math.min(0,Math.max(size-cropHeight,cropTop));
            photoPreview.style.position='absolute';photoPreview.style.maxWidth='none';photoPreview.style.maxHeight='none';
            photoPreview.style.objectFit='fill';photoPreview.style.width=cropWidth+'px';photoPreview.style.height=cropHeight+'px';
            photoPreview.style.left=cropLeft+'px';photoPreview.style.top=cropTop+'px';
          }
          function resetCrop(){
            if(!cropImage||optimizingPhoto)return;
            var size=cropViewport.clientWidth;
            cropBaseScale=Math.max(size/cropImage.naturalWidth,size/cropImage.naturalHeight);
            cropZoom=1;zoomInput.value='1';zoomInput.disabled=false;resetCropButton.disabled=false;
            cropWidth=cropImage.naturalWidth*cropBaseScale;cropHeight=cropImage.naturalHeight*cropBaseScale;
            cropLeft=(size-cropWidth)/2;cropTop=(size-cropHeight)/2;
            constrainCrop();
          }
          function encodeCrop(canvas){
            return new Promise(function(resolve,reject){
              canvas.toBlob(function(webp){
                if(webp&&webp.type==='image/webp'){resolve(webp);return}
                canvas.toBlob(function(jpeg){
                  if(jpeg)resolve(jpeg);else reject(new Error('The cropped image could not be created.'));
                },'image/jpeg',.84);
              },'image/webp',.84);
            });
          }
          function makeCroppedPhoto(){
            if(!cropImage||!cropViewport.clientWidth)return Promise.reject(new Error('Choose a photo and adjust its crop first.'));
            var outputSize=640,scale=outputSize/cropViewport.clientWidth,canvas=document.createElement('canvas');
            canvas.width=outputSize;canvas.height=outputSize;
            var context=canvas.getContext('2d');
            if(!context)return Promise.reject(new Error('Photo cropping is unavailable in this browser.'));
            context.drawImage(cropImage,cropLeft*scale,cropTop*scale,cropWidth*scale,cropHeight*scale);
            return encodeCrop(canvas);
          }
          function open(p){
            photoJob++;optimizingPhoto=false;photoPrepared=false;selectedFile=null;cropImage=null;dragState=null;
            if(previewUrl)URL.revokeObjectURL(previewUrl);
            previewUrl='';photoPreview.onload=null;photoPreview.removeAttribute('style');
            $('pd-title').textContent=p&&p.id?'Edit pet':'Add a pet';$('pd-id').value=p&&p.id?p.id:0;
            $('pd-name').value=p&&p.name?p.name:'';$('pd-species').value=p&&p.species?p.species:'';
            $('pd-breed').value=p&&p.breed?p.breed:'';$('pd-gender').value=p&&p.gender?p.gender:'';
            $('pd-birth').value=p&&p.birth?p.birth:'';photoInput.value='';
            photoHint.textContent='JPG, PNG or WebP. Your crop is optimized before upload.';
            zoomInput.disabled=true;zoomInput.value='1';resetCropButton.disabled=true;
            removePhotoInput.value='0';savedPhoto=p&&p.photo?p.photo:'';
            $('pd-crop-help').textContent=savedPhoto?'Choose a new photo to adjust the crop, or keep the current one.':'Choose a photo, then drag and zoom to frame it.';
            setPreview(savedPhoto);d.showModal();
          }
          document.querySelectorAll('#add-pet').forEach(function(b){b.addEventListener('click',function(){open()})});
          document.querySelectorAll('.edit-pet').forEach(function(b){b.addEventListener('click',function(){open(b.dataset)})});
          photoInput.addEventListener('change',function(){
            if(previewUrl)URL.revokeObjectURL(previewUrl);
            var file=this.files[0],job=++photoJob;
            selectedFile=file||null;cropImage=null;photoPrepared=false;optimizingPhoto=false;
            previewUrl=file?URL.createObjectURL(file):'';
            removePhotoInput.value='0';
            photoPreview.removeAttribute('style');
            photoPreview.onload=null;
            zoomInput.disabled=true;zoomInput.value='1';resetCropButton.disabled=true;
            if(!file){
              setPreview(savedPhoto);
              $('pd-crop-help').textContent=savedPhoto?'Choose a new photo to adjust the crop, or keep the current one.':'Choose a photo, then drag and zoom to frame it.';
              photoHint.textContent='Select a photo to start cropping.';return
            }
            photoHint.textContent='Drag the photo in the square to position your pet.';
            $('pd-crop-help').textContent='Drag to reposition your pet. Use the zoom control to refine the square crop.';
            photoPreview.onload=function(){
              if(job!==photoJob||!selectedFile)return;
              cropImage=photoPreview;resetCrop();
            };
            photoPreview.onerror=function(){
              if(job===photoJob){
                cropImage=null;photoPreview.hidden=true;$('pd-photo-placeholder').hidden=false;
                zoomInput.disabled=true;resetCropButton.disabled=true;
                photoHint.textContent='This photo could not be opened. Please choose another image.';
              }
            };
            setPreview(previewUrl);
          });
          cropViewport.addEventListener('pointerdown',function(event){
            if(optimizingPhoto||!selectedFile||!cropImage)return;
            event.preventDefault();
            dragState={x:event.clientX,y:event.clientY,left:cropLeft,top:cropTop};
            cropViewport.setPointerCapture(event.pointerId);
          });
          cropViewport.addEventListener('pointermove',function(event){
            if(optimizingPhoto||!dragState)return;
            cropLeft=dragState.left+event.clientX-dragState.x;
            cropTop=dragState.top+event.clientY-dragState.y;
            constrainCrop();
          });
          ['pointerup','pointercancel','lostpointercapture'].forEach(function(name){
            cropViewport.addEventListener(name,function(){dragState=null});
          });
          cropViewport.addEventListener('keydown',function(event){
            if(optimizingPhoto||!selectedFile||!cropImage)return;
            var step=event.shiftKey?20:5;
            if(event.key==='ArrowLeft')cropLeft-=step;
            else if(event.key==='ArrowRight')cropLeft+=step;
            else if(event.key==='ArrowUp')cropTop-=step;
            else if(event.key==='ArrowDown')cropTop+=step;
            else return;
            event.preventDefault();constrainCrop();
          });
          zoomInput.addEventListener('input',function(){
            if(optimizingPhoto||!cropImage)return;
            var nextZoom=Number(this.value),ratio=nextZoom/cropZoom,size=cropViewport.clientWidth;
            cropLeft=size/2-(size/2-cropLeft)*ratio;
            cropTop=size/2-(size/2-cropTop)*ratio;
            cropZoom=nextZoom;constrainCrop();
          });
          resetCropButton.addEventListener('click',resetCrop);
          petForm.addEventListener('submit',function(event){
            if(optimizingPhoto){
              event.preventDefault();
              photoHint.textContent='Please wait while the crop is prepared.';
            }else if(selectedFile&&!photoPrepared){
              event.preventDefault();
              if(!cropImage){
                photoHint.textContent='Wait for the photo preview to finish loading, then save again.';
                return;
              }
              var job=photoJob;
              optimizingPhoto=true;photoHint.textContent='Preparing your cropped photo…';
              makeCroppedPhoto().then(function(blob){
                if(job!==photoJob)return;
                var extension=blob.type==='image/webp'?'webp':'jpg';
                var filename=(selectedFile.name.replace(/\.[^.]+$/,'')||'pet-photo')+'-crop.'+extension;
                var transfer=new DataTransfer();
                transfer.items.add(new File([blob],filename,{type:blob.type,lastModified:selectedFile.lastModified}));
                photoInput.files=transfer.files;photoPrepared=true;optimizingPhoto=false;
                photoHint.textContent='Cropped photo ready ('+Math.round(blob.size/1024)+' KB).';
                if(petForm.requestSubmit)petForm.requestSubmit($('pd-save'));else petForm.submit();
              }).catch(function(){
                if(job!==photoJob)return;
                optimizingPhoto=false;
                photoHint.textContent='The crop could not be prepared. Please reset the crop or choose another photo.';
              });
            }
          });
          removePhotoButton.addEventListener('click',function(){
            var removing=removePhotoInput.value!=='1';
            removePhotoInput.value=removing?'1':'0';
            if(removing){
              photoJob++;optimizingPhoto=false;selectedFile=null;cropImage=null;photoPrepared=false;photoInput.value='';
              photoPreview.onload=null;zoomInput.disabled=true;resetCropButton.disabled=true;
              photoHint.textContent='The current photo will be removed when you save.';
              if(previewUrl)URL.revokeObjectURL(previewUrl);previewUrl='';
              $('pd-crop-help').textContent='Click Keep current photo to undo this change.';
            }else{
              photoHint.textContent='JPG, PNG or WebP. Your crop is optimized before upload.';
              $('pd-crop-help').textContent='Choose a new photo to adjust the crop, or keep the current one.';
            }
            setPreview(removing?'':savedPhoto);
          });
          var deleteDialog=$('delete-dialog');
          document.querySelectorAll('.delete-pet:not(:disabled)').forEach(function(button){
            button.addEventListener('click',function(){
              $('delete-pet-id').value=button.dataset.id;
              $('delete-pet-name').textContent=button.dataset.name;
              deleteDialog.showModal();
            });
          });
          $('delete-cancel').addEventListener('click',function(){deleteDialog.close()});
          $('pd-close').addEventListener('click',function(){if(returnToBooking){window.location.href='book_appointment.php'}else{d.close()}});
          d.addEventListener('cancel',function(event){if(returnToBooking){event.preventDefault();window.location.href='book_appointment.php'}});
          <?php if ($error && ($old['action'] ?? '') === 'save_pet'): ?>open({id:<?= (int) ($old['pet_id'] ?? 0) ?>,name:<?= json_encode($old['pet_name'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,species:<?= json_encode($old['species'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,breed:<?= json_encode($old['breed'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,gender:<?= json_encode($old['gender'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,birth:<?= json_encode($old['birth_date'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,photo:<?= json_encode($oldPetPhoto, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>});<?php elseif ($returnToBooking): ?>open();<?php endif; ?>
        })();
        </script>
<?php client_footer($c);