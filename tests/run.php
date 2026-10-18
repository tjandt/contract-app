<?php
/**
 * Minimaler Test-Runner für die Domain-Logik (ohne Composer/PHPUnit, ohne Datenbank).
 *
 *   php tests/run.php
 *
 * Exit-Code 0 = alle Tests grün, 1 = mindestens ein Test fehlgeschlagen.
 */

date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../includes/domain.php';

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = [];

function d(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date);
}

function check(string $name, mixed $expected, mixed $actual): void
{
    if ($expected instanceof DateTimeImmutable) $expected = $expected->format('Y-m-d');
    if ($actual instanceof DateTimeImmutable)   $actual   = $actual->format('Y-m-d');
    if ($expected === $actual || (is_float($expected) && is_float($actual) && abs($expected - $actual) < 0.0001)) {
        $GLOBALS['t_pass']++;
        return;
    }
    $GLOBALS['t_fail'][] = sprintf("  ✗ %s\n      erwartet: %s\n      erhalten: %s", $name, var_export($expected, true), var_export($actual, true));
}

/** Standard-Vertrag mit überschreibbaren Feldern. */
function contract(array $o = []): array
{
    return $o + [
        'id' => 1, 'name' => 'Test', 'status' => 'active', 'start_date' => '2025-01-01',
        'initial_term_months' => 12, 'renewal_term_months' => 12, 'notice_period_days' => 30,
        'notice_period_unit' => 'days', 'end_alignment' => 'none', 'billing_cycle' => 'monthly',
        'current_cost' => 10.0, 'cancelled_at' => null, 'cancellation_submitted_at' => null,
        'category_id' => null, 'category_name' => null, 'color_hex' => '#8E8E93', 'icon' => '📄',
    ];
}

function hist(string $date, float $amount, ?string $reason = null, int $id = 0): array
{
    return ['id' => $id, 'valid_from' => $date, 'amount' => $amount, 'reason' => $reason];
}

// ---------------------------------------------------------------- Datumsarithmetik
check('add_months: normal', '2025-07-15', add_months_clamped(d('2025-01-15'), 6));
check('add_months: 31.08. + 6 Monate = 28.02. (kein Überlauf)', '2026-02-28', add_months_clamped(d('2025-08-31'), 6));
check('add_months: Schaltjahr', '2028-02-29', add_months_clamped(d('2027-08-31'), 6));
check('add_months: negativ', '2025-11-30', add_months_clamped(d('2026-01-30'), -2));
check('term_last_day: 01.01. + 12M = 31.12.', '2025-12-31', term_last_day(d('2025-01-01'), 12));
check('term_last_day: 15.03. + 24M = 14.03.', '2027-03-14', term_last_day(d('2025-03-15'), 24));
check('term_last_day: 31.08. + 6M = 28.02.', '2026-02-28', term_last_day(d('2025-08-31'), 6));
check('term_last_day: 0 Monate = Vortag', '2024-12-31', term_last_day(d('2025-01-01'), 0));
check('align month_end', '2025-03-31', align_term_end(d('2025-03-14'), 'month_end'));
check('align quarter_end (Feb)', '2025-03-31', align_term_end(d('2025-02-10'), 'quarter_end'));
check('align quarter_end (schon Quartalsende)', '2025-06-30', align_term_end(d('2025-06-30'), 'quarter_end'));
check('align quarter_end (Okt)', '2025-12-31', align_term_end(d('2025-10-01'), 'quarter_end'));
check('align year_end', '2025-12-31', align_term_end(d('2025-03-14'), 'year_end'));
check('align none', '2025-03-14', align_term_end(d('2025-03-14'), 'none'));
check('notice: 30 Tage', '2025-12-01', notice_deadline(d('2025-12-31'), 30, 'days'));
check('notice: 3 Monate zum Monatsende', '2025-09-30', notice_deadline(d('2025-12-31'), 3, 'months'));
check('notice: 1 Monat zum Monatsende (Feb)', '2025-01-31', notice_deadline(d('2025-02-28'), 1, 'months'));
check('notice: 3 Monate, Ende mitten im Monat', '2025-03-14', notice_deadline(d('2025-06-14'), 3, 'months'));

// ---------------------------------------------------------------- Kündigungsfrist
$today = d('2026-10-18');

$r = calculate_contract_deadline(contract(), $today);
check('Standard: Zustand', 'cancellable', $r['state']);
check('Standard: Periodenende', '2026-12-31', $r['period_end']);
check('Standard: Frist = 31.12. - 30 Tage', '2026-12-01', $r['cancel_deadline']);
check('Standard: Tage', 44, $r['days_left']);
check('Standard: keine gesperrte Periode', null, $r['locked_period_end']);

// Bug 1: monatlich verlängernd darf nicht dauerhaft "verpasst" sein
$r = calculate_contract_deadline(contract(['start_date' => '2026-04-01', 'initial_term_months' => 1, 'renewal_term_months' => 1]), $today);
check('Monatlich: Zustand', 'cancellable', $r['state']);
check('Monatlich: nächste erreichbare Periode', '2026-11-30', $r['period_end']);
check('Monatlich: Frist', '2026-10-31', $r['cancel_deadline']);
check('Monatlich: aktuelle Periode ist gesperrt', '2026-10-31', $r['locked_period_end']);
check('Monatlich: nie negativ', true, $r['days_left'] >= 0);

// Bug 2: ohne Verlängerung -> kein Dauer-Reminder
$r = calculate_contract_deadline(contract(['start_date' => '2026-01-01', 'initial_term_months' => 1, 'renewal_term_months' => 0]), $today);
check('Verl.0, Laufzeit vorbei: Zustand open', 'open', $r['state']);
check('Verl.0, Laufzeit vorbei: kein Reminder', null, $r['days_left']);
check('Verl.0, Laufzeit vorbei: früheste Beendigung', '2026-11-17', $r['earliest_end']);
check('Verl.0, Laufzeit vorbei: keine Dringlichkeit', null, deadline_urgency($r['days_left']));

$r = calculate_contract_deadline(contract(['start_date' => '2026-03-01', 'initial_term_months' => 12, 'renewal_term_months' => 0]), $today);
check('Verl.0, Laufzeit läuft: Zustand ends', 'ends', $r['state']);
check('Verl.0, Laufzeit läuft: endet von selbst', '2027-02-28', $r['period_end']);
check('Verl.0, Laufzeit läuft: kein "Frist verpasst"', null, $r['days_left']);

// Bug 3 + 4: Monatsende-Überlauf & Off-by-one
$r = calculate_contract_deadline(contract(['start_date' => '2025-08-31', 'initial_term_months' => 6, 'renewal_term_months' => 12]), $today);
check('31.08.-Start: Periodenende ohne Überlauf', '2027-02-28', $r['period_end']);

// Frist verpasst vor 5 Tagen -> nächste Periode, aktuelle gesperrt
$r = calculate_contract_deadline(contract(['start_date' => '2025-11-10', 'initial_term_months' => 12, 'renewal_term_months' => 12]), $today);
check('Frist verstrichen: Periodenende der gesperrten Periode', '2026-11-09', $r['locked_period_end']);
check('Frist verstrichen: nächste Periode', '2027-11-09', $r['period_end']);
check('Frist verstrichen: Frist der nächsten Periode', '2027-10-10', $r['cancel_deadline']);

// Ausrichtung & Monate
$r = calculate_contract_deadline(contract([
    'start_date' => '2025-03-15', 'initial_term_months' => 24, 'renewal_term_months' => 12,
    'notice_period_days' => 3, 'notice_period_unit' => 'months', 'end_alignment' => 'quarter_end',
]), $today);
check('Quartalsende/3 Monate: Ende', '2027-03-31', $r['period_end']);
check('Quartalsende/3 Monate: Frist', '2026-12-31', $r['cancel_deadline']);

// Zukunft & Zustände
$r = calculate_contract_deadline(contract(['start_date' => '2026-12-01']), $today);
check('Start in Zukunft: Ende', '2027-11-30', $r['period_end']);
check('Pausiert: kein Reminder', 'none', calculate_contract_deadline(contract(['status' => 'paused']), $today)['state']);
check('Gekündigt: kein Reminder', 'none', calculate_contract_deadline(contract(['cancelled_at' => '2026-12-31']), $today)['state']);
check('Kündigung eingereicht: kein Reminder', 'none', calculate_contract_deadline(contract(['cancellation_submitted_at' => '2026-10-01']), $today)['state']);

// Kündigung eingereicht -> Vertragsende berechnen
check('Einreichung vor Frist -> Periodenende', '2026-12-31', compute_termination_end(contract(), d('2026-11-15')));
check('Einreichung nach Frist -> nächste Periode', '2027-12-31', compute_termination_end(contract(), d('2026-12-15')));
check('Einreichung bei unbefristetem Vertrag', '2026-11-17',
    compute_termination_end(contract(['start_date' => '2026-01-01', 'initial_term_months' => 1, 'renewal_term_months' => 0]), $today));
check('Einreichung ohne Verlängerung, läuft aus', '2027-02-28',
    compute_termination_end(contract(['start_date' => '2026-03-01', 'renewal_term_months' => 0]), $today));

// Dringlichkeit
check('Dringlichkeit urgent', 'urgent', deadline_urgency(10));
check('Dringlichkeit warn', 'warn', deadline_urgency(60));
check('Dringlichkeit ok', 'ok', deadline_urgency(200));
check('Dringlichkeit heute', 'urgent', deadline_urgency(0));

// ---------------------------------------------------------------- Preise
$h = [hist('2025-01-01', 10.0), hist('2025-06-01', 12.0), hist('2026-02-01', 15.0)];
check('price_at: vor Beginn -> ältester Preis', 10.0, price_at($h, '2024-06-01'));
check('price_at: mitten drin', 12.0, price_at($h, '2025-12-31'));
check('price_at: am Stichtag', 15.0, price_at($h, '2026-02-01'));
check('price_at: leere Historie', null, price_at([], '2026-01-01'));

$changes = history_changes([hist('2025-01-01', 10.0, 'Vertragsbeginn', 1), hist('2025-06-01', 12.0, 'Preiserhöhung', 2),
    hist('2025-07-01', 12.0, 'Preisanpassung', 3), hist('2026-02-01', 9.0, null, 4)]);
check('history_changes: Anzahl (gleicher Betrag ignoriert)', 2, count($changes));
check('history_changes: Erhöhung %', 20.0, $changes[0]['pct']);
check('history_changes: Senkung', 'down', $changes[1]['direction']);

// ---------------------------------------------------------------- Monats-/Jahreskosten
$cs = [
    contract(['id' => 1, 'name' => 'A', 'start_date' => '2025-01-01', 'billing_cycle' => 'monthly']),
    contract(['id' => 2, 'name' => 'B', 'start_date' => '2025-06-15', 'billing_cycle' => 'yearly', 'cancelled_at' => '2026-03-10']),
    contract(['id' => 3, 'name' => 'C', 'start_date' => '2025-01-01', 'billing_cycle' => 'monthly', 'status' => 'paused']),
];
$hs = [
    1 => [hist('2025-01-01', 10.0), hist('2026-01-01', 12.0)],
    2 => [hist('2025-06-15', 120.0)],
    3 => [hist('2025-01-01', 5.0)],
];
$todayStr = '2026-10-18';

$items = month_cost_items($cs, $hs, '2025-05', $todayStr);
check('Mai 2025: A + C (B noch nicht gestartet)', 15.0, month_cost_total($items));
$items = month_cost_items($cs, $hs, '2025-06', $todayStr);
check('Juni 2025: Startmonat zählt voll', 25.0, month_cost_total($items));
$items = month_cost_items($cs, $hs, '2026-03', $todayStr);
check('März 2026: Kündigungsmonat zählt noch', 27.0, month_cost_total($items));
$items = month_cost_items($cs, $hs, '2026-04', $todayStr);
check('April 2026: B beendet', 17.0, month_cost_total($items));
$items = month_cost_items($cs, $hs, '2026-10', $todayStr);
check('Okt 2026 (aktuell): pausiertes C entfällt', 12.0, month_cost_total($items));

$series = monthly_cost_series($cs, $hs, '2025-11', '2026-02', $todayStr);
check('Serie: Anzahl Monate', 4, count($series));
check('Serie: Summe stimmt mit Einzelmonat überein', 27.0, $series['2026-02']);

$year = year_cost_breakdown($cs, $hs, '2025', $todayStr);
check('Jahr 2025: 12 Monate', 12, $year['months']);
// A: 10*12 = 120, B: ab Juni (7 Monate à 10) = 70, C: 5*12 = 60
check('Jahr 2025: Gesamt', 250.0, $year['total']);
$year = year_cost_breakdown($cs, $hs, '2026', $todayStr);
check('Jahr 2026: nur bis Oktober', 10, $year['months']);
check('Jahr 2026: Gesamt (A 10*12, B Jan-Mär 3*10, C Jan-Sep 9*5)', 195.0, $year['total']);
check('Jahr 2027 (Zukunft): leer', 0, count(year_cost_breakdown($cs, $hs, '2027', $todayStr)['items']));

// Run-Rate
$rr = run_rate_items($cs, $hs, '2026-10-18');
check('Run-Rate heute: nur A', 12.0, month_cost_total($rr));
$rr = run_rate_items($cs, $hs, '2026-02-28');
check('Run-Rate Ende Feb 2026: A + B', 22.0, month_cost_total($rr));
check('Run-Rate: noch nicht gestartet', 0, count(run_rate_items([contract(['start_date' => '2027-01-01'])], [1 => [hist('2027-01-01', 9.0)]], '2026-10-18')));

// ---------------------------------------------------------------- Zahlungen
$pc = [
    contract(['id' => 1, 'name' => 'Monat', 'start_date' => '2026-01-31', 'billing_cycle' => 'monthly']),
    contract(['id' => 2, 'name' => 'Quartal', 'start_date' => '2025-02-01', 'billing_cycle' => 'quarterly']),
    contract(['id' => 3, 'name' => 'Jahr', 'start_date' => '2024-05-01', 'billing_cycle' => 'yearly', 'billing_anchor_date' => '2025-01-15']),
    contract(['id' => 4, 'name' => 'Ende', 'start_date' => '2025-01-01', 'billing_cycle' => 'monthly', 'cancelled_at' => '2026-11-15']),
];
$ph = [1 => [hist('2026-01-31', 10.0)], 2 => [hist('2025-02-01', 30.0), hist('2026-05-01', 33.0)],
       3 => [hist('2024-05-01', 100.0)], 4 => [hist('2025-01-01', 1.0)]];
$pay = payments_in_range($pc, $ph, d('2026-11-01'), d('2027-02-28'), $todayStr);
$byName = [];
foreach ($pay as $p) { $byName[$p['name']][] = $p['date']; }
check('Monatlich mit Monatsende-Anker', ['2026-11-30', '2026-12-31', '2027-01-31', '2027-02-28'], $byName['Monat']);
check('Quartalsweise (Feb/Mai/Aug/Nov)', ['2026-11-01', '2027-02-01'], $byName['Quartal']);
check('Quartalspreis nach Erhöhung', 33.0, array_values(array_filter($pay, fn($p) => $p['name'] === 'Quartal'))[0]['amount']);
check('Jährlich mit eigenem Anker', ['2027-01-15'], $byName['Jahr']);
check('Gekündigter Vertrag: keine Abbuchung nach Ende', ['2026-11-01'], $byName['Ende']);
check('Sortierung nach Datum', true, $pay === array_values($pay) && $pay[0]['date'] <= $pay[count($pay) - 1]['date']);
check('Pausierter Vertrag: keine künftigen Abbuchungen', 0,
    count(payments_in_range([contract(['status' => 'paused'])], [1 => [hist('2025-01-01', 10.0)]], d('2026-11-01'), d('2027-01-31'), $todayStr)));

// ---------------------------------------------------------------- Ergebnis
$fail = $GLOBALS['t_fail'];
printf("%d Prüfungen bestanden, %d fehlgeschlagen\n", $GLOBALS['t_pass'], count($fail));
if ($fail) {
    echo implode("\n", $fail), "\n";
    exit(1);
}
