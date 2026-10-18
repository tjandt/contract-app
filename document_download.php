<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = get_pdo();
$stmt = $pdo->prepare("SELECT * FROM contract_documents WHERE id = ?");
$stmt->execute([(int) ($_GET['id'] ?? 0)]);
$doc = $stmt->fetch();

$path = $doc ? document_path($doc['stored_name']) : null;
if (!$doc || !is_file($path)) {
    http_response_code(404);
    die('Dokument nicht gefunden.');
}

// Nur harmlose Typen dürfen direkt im Browser angezeigt werden, alles andere wird heruntergeladen
$inline = isset($_GET['inline']) && in_array($doc['mime_type'], DOCUMENT_INLINE_TYPES, true);
$asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $doc['original_name']);

header('Content-Type: ' . $doc['mime_type']);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($doc['original_name']));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
