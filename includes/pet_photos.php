<?php
// Pet photos are stored in Pets.photo_data (MEDIUMBLOB).
const PET_PHOTO_MAX_BYTES = 2097152;
const PET_PHOTO_TYPES = ['image/jpeg' => 'JPG', 'image/png' => 'PNG', 'image/webp' => 'WebP'];

/** Validate an upload and return its binary data and MIME type, or null if no file was selected. */
function pet_photo_read_upload(array $file): ?array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new InvalidArgumentException('The photo is larger than 2 MB. Choose a smaller image.');
    }
    if ($error !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('The photo could not be uploaded. Please try again.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > PET_PHOTO_MAX_BYTES) {
        throw new InvalidArgumentException('The photo must be 2 MB or smaller.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(PET_PHOTO_TYPES[$mime])) {
        throw new InvalidArgumentException('Upload a JPG, PNG or WebP image.');
    }

    $image = @getimagesize($file['tmp_name']);
    if (!$image || ($image['mime'] ?? '') !== $mime
        || $image[0] < 1 || $image[1] < 1 || $image[0] > 6000 || $image[1] > 6000) {
        throw new InvalidArgumentException('That file is not a valid image.');
    }

    $data = file_get_contents($file['tmp_name']);
    if ($data === false) {
        throw new InvalidArgumentException('The photo could not be read. Please try again.');
    }

    return ['data' => $data, 'mime' => $mime];
}

/** Store or remove a pet's photo. Pass null to clear the existing photo. */
function pet_photo_save(PDO $pdo, int $petId, ?array $photo): void
{
    if ($photo === null) {
        $pdo->prepare('UPDATE Pets SET photo_data = NULL, photo_mime = NULL, photo_updated_at = NULL WHERE pet_id = ?')
            ->execute([$petId]);
        return;
    }

    $st = $pdo->prepare('UPDATE Pets SET photo_data = ?, photo_mime = ?, photo_updated_at = NOW() WHERE pet_id = ?');
    $st->bindValue(1, $photo['data'], PDO::PARAM_LOB);
    $st->bindValue(2, $photo['mime']);
    $st->bindValue(3, $petId, PDO::PARAM_INT);
    $st->execute();
}

function pet_photo_url($version, int $petId): string
{
    return $version
        ? app_path('api/pet_photo.php') . '?id=' . $petId
            . '&session_context=' . rawurlencode(auth_session_context())
            . '&v=' . rawurlencode((string) $version)
        : '';
}
