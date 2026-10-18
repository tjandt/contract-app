<?php
require_once __DIR__ . '/includes/bootstrap.php';
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

/** Maskiert Text nach RFC 5545 (Backslash, Semikolon, Komma, Zeilenumbruch). */
function ics_escape(string $text): string
{
    return str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\;", "\\,", "\\n", "\\n", "\\n"], $text);
}

/** Bricht Zeilen nach 75 Oktetten um (RFC 5545 "line folding"), ohne Multibyte-Zeichen zu zerteilen. */
function ics_fold(string $line): string
{
    $out = '';
    $current = '';
    foreach (mb_str_split($line) as $char) {
        if (strlen($current . $char) > ($out === '' ? 75 : 74)) {
            $out .= ($out === '' ? '' : "\r\n ") . $current;
            $current = '';
        }
        $current .= $char;
    }
    return $out . ($out === '' ? '' : "\r\n ") . $current;
}

$deadlineDate = $deadline['cancel_deadline'];
$date    = $deadlineDate->format('Ymd');
$dateEnd = $deadlineDate->modify('+1 day')->format('Ymd'); // DTEND ist bei Ganztagsterminen exklusiv

$description = 'Letzter Termin, um "' . $contract['name'] . '" fristgerecht zu kündigen. '
    . 'Vertragsende bei Kündigung: ' . format_date_de($deadline['period_end']->format('Y-m-d')) . '.';
if ($contract['provider_url']) {
    $description .= "\nAnbieter: " . $contract['provider_url'];
}

$lines = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Vertragsverwaltung//DE',
    'CALSCALE:GREGORIAN',
    'BEGIN:VEVENT',
    'UID:contract-' . $id . '-' . $date . '@vertragsverwaltung.local',
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART;VALUE=DATE:' . $date,
    'DTEND;VALUE=DATE:' . $dateEnd,
    'SUMMARY:' . ics_escape('Kündigungsfrist: ' . $contract['name']),
    'DESCRIPTION:' . ics_escape($description),
    'BEGIN:VALARM',
    'ACTION:DISPLAY',
    'DESCRIPTION:' . ics_escape('Kündigungsfrist läuft bald ab: ' . $contract['name']),
    'TRIGGER:-P7D',
    'END:VALARM',
    'END:VEVENT',
    'END:VCALENDAR',
];
$ics = implode("\r\n", array_map('ics_fold', $lines)) . "\r\n";

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="kuendigung-' . trim(preg_replace('/[^a-z0-9]+/i', '-', $contract['name']), '-') . '.ics"');
echo $ics;
