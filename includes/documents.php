<?php
/**
 * Dokumente zu Verträgen (PDF-Verträge, Kündigungsbestätigungen, Rechnungen, …).
 *
 * Die Dateien liegen unter DOCUMENT_DIR (idealerweise außerhalb des Webroots) unter zufälligen Namen;
 * die Datenbank speichert Originalname, Typ und Größe. Ausgeliefert wird ausschließlich über
 * document_download.php nach Login-Prüfung.
 */

const DOCUMENT_TYPES = ['contract', 'cancellation', 'invoice', 'other'];

/** Erlaubte Endungen und die dazu passenden MIME-Typen (per finfo am Dateiinhalt geprüft). */
const DOCUMENT_ALLOWED = [
    'pdf'  => ['application/pdf'],
    'png'  => ['image/png'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'webp' => ['image/webp'],
    'txt'  => ['text/plain'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
];

/** Dateitypen, die der Browser direkt anzeigen darf (alles andere wird als Download ausgeliefert). */
const DOCUMENT_INLINE_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'];

/** Legt das Speicherverzeichnis an und sperrt es für direkte Web-Zugriffe (Apache). */
function document_storage_dir(): string
{
    $dir = DOCUMENT_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Dokumentenverzeichnis konnte nicht angelegt werden: ' . $dir);
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

function document_path(string $storedName): string
{
    if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $storedName)) {
        throw new InvalidArgumentException('Ungültiger Dateiname.');
    }
    return document_storage_dir() . '/' . $storedName;
}

function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu groß (Server-Limit).',
        UPLOAD_ERR_PARTIAL                        => 'Die Datei wurde nur teilweise hochgeladen.',
        UPLOAD_ERR_NO_FILE                        => 'Bitte eine Datei auswählen.',
        default                                   => 'Der Upload ist fehlgeschlagen (Code ' . $code . ').',
    };
}

/**
 * Prüft und speichert eine hochgeladene Datei.
 *
 * @param array $file Eintrag aus $_FILES
 * @return string|null Fehlermeldung oder null bei Erfolg
 */
function store_uploaded_document(PDO $pdo, int $contractId, array $file, string $docType): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return upload_error_message((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE));
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return 'Ungültiger Upload.';
    }
    if ($file['size'] > DOCUMENT_MAX_BYTES) {
        return 'Die Datei ist zu groß (max. ' . format_bytes(DOCUMENT_MAX_BYTES) . ').';
    }
    if (!in_array($docType, DOCUMENT_TYPES, true)) {
        $docType = 'other';
    }

    $originalName = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', (string) $file['name']));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!isset(DOCUMENT_ALLOWED[$ext])) {
        return 'Dateityp nicht erlaubt. Erlaubt: ' . implode(', ', array_keys(DOCUMENT_ALLOWED)) . '.';
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!in_array($mime, DOCUMENT_ALLOWED[$ext], true)) {
        return 'Der Dateiinhalt passt nicht zur Endung .' . $ext . ' (erkannt: ' . $mime . ').';
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    $target = document_path($storedName);
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return 'Die Datei konnte nicht gespeichert werden (Schreibrechte für das Dokumentenverzeichnis prüfen).';
    }
    chmod($target, 0640);

    try {
        $pdo->prepare("INSERT INTO contract_documents (contract_id, doc_type, original_name, stored_name, mime_type, size_bytes)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$contractId, $docType, mb_substr($originalName, 0, 255), $storedName, $mime, (int) $file['size']]);
    } catch (Throwable $e) {
        @unlink($target);
        error_log('store_uploaded_document: ' . $e->getMessage());
        return 'Die Datei konnte nicht in der Datenbank vermerkt werden.';
    }
    return null;
}

/** Löscht alle Dokument-Dateien eines Vertrags (die Datenbankzeilen entfernt ON DELETE CASCADE). */
function delete_contract_document_files(PDO $pdo, int $contractId): void
{
    $stmt = $pdo->prepare("SELECT stored_name FROM contract_documents WHERE contract_id = ?");
    $stmt->execute([$contractId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $storedName) {
        @unlink(document_path($storedName));
    }
}

function get_contract_documents(PDO $pdo, int $contractId): array
{
    $stmt = $pdo->prepare("SELECT * FROM contract_documents WHERE contract_id = ? ORDER BY uploaded_at DESC, id DESC");
    $stmt->execute([$contractId]);
    return $stmt->fetchAll();
}
