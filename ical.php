<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = get_pdo();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM contracts WHERE id = ?");
$stmt->execute([$id]);
$contract = $stmt->fetch();

if (!$contract) {
    http_response_code(404);
    die('Vertrag nicht gefunden.');
}

$deadline = calculate_contract_deadline($contract);

if (!$deadline['cancel_deadline']) {
    die('Für diesen Vertrag gibt es aktuell keine berechnete Kündigungsfrist.');
}

$date = $deadline['cancel_deadline']->format('Ymd');
$uid = 'contract-' . $id . '-' . $date . '@vertragsverwaltung.local';
$summary = 'Kündigungsfrist: ' . $contract['name'];
$description = 'Letzter Termin, um "' . $contract['name'] . '" fristgerecht zu kündigen.';
if ($contract['provider_url']) {
    $description .= '\\nAnbieter: ' . $contract['provider_url'];
}

$ics = "BEGIN:VCALENDAR\r\n";
$ics .= "VERSION:2.0\r\n";
$ics .= "PRODID:-//Vertragsverwaltung//DE\r\n";
$ics .= "CALSCALE:GREGORIAN\r\n";
$ics .= "BEGIN:VEVENT\r\n";
$ics .= "UID:" . $uid . "\r\n";
$ics .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
$ics .= "DTSTART;VALUE=DATE:" . $date . "\r\n";
$ics .= "DTEND;VALUE=DATE:" . $date . "\r\n";
$ics .= "SUMMARY:" . $summary . "\r\n";
$ics .= "DESCRIPTION:" . $description . "\r\n";
$ics .= "BEGIN:VALARM\r\n";
$ics .= "ACTION:DISPLAY\r\n";
$ics .= "DESCRIPTION:Kündigungsfrist läuft bald ab\r\n";
$ics .= "TRIGGER:-P7D\r\n";
$ics .= "END:VALARM\r\n";
$ics .= "END:VEVENT\r\n";
$ics .= "END:VCALENDAR\r\n";

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="kuendigung-' . preg_replace('/[^a-z0-9]+/i', '-', $contract['name']) . '.ics"');
echo $ics;
