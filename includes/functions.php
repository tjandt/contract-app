<?php
/**
 * Datenbankgestützte Auswertungen und Darstellungs-Helfer.
 * Die eigentliche Rechenlogik (Fristen, Preise, Kosten) steht in domain.php.
 */

require_once __DIR__ . '/domain.php';

// ------------------------------------------------------------------
// Beschriftungen & Status
// ------------------------------------------------------------------

function cycle_label(string $cycle): string
{
    return match ($cycle) {
        'yearly'    => 'jährlich',
        'quarterly' => 'vierteljährlich',
        default     => 'monatlich',
    };
}

function notice_label(array $contract): string
{
    $value = (int) $contract['notice_period_days'];
    if (($contract['notice_period_unit'] ?? 'days') === 'months') {
        return $value . ($value === 1 ? ' Monat' : ' Monate');
    }
    return $value . ($value === 1 ? ' Tag' : ' Tage');
}

function end_alignment_label(string $alignment): string
{
    return match ($alignment) {
        'month_end'   => 'zum Monatsende',
        'quarter_end' => 'zum Quartalsende',
        'year_end'    => 'zum Jahresende',
        default       => 'zum Laufzeitende',
    };
}

function doc_type_label(string $type): string
{
    return match ($type) {
        'contract'     => 'Vertrag',
        'cancellation' => 'Kündigung',
        'invoice'      => 'Rechnung',
        default        => 'Sonstiges',
    };
}

/**
 * Ein Vertrag "läuft", solange er nicht pausiert ist und sein Kündigungsdatum (falls gesetzt)
 * noch nicht überschritten wurde. Bereits gekündigte, aber noch laufende Verträge zählen so
 * korrekt zu den Kosten und fallen am Stichtag automatisch heraus.
 */
function is_contract_running(array $contract, ?DateTimeImmutable $today = null): bool
{
    if (($contract['status'] ?? 'active') === 'paused') {
        return false;
    }
    if (empty($contract['cancelled_at'])) {
        return true;
    }
    $today ??= new DateTimeImmutable('today');
    return $contract['cancelled_at'] >= $today->format('Y-m-d');
}

/** Sprechender Status inkl. Datum, z. B. "Gekündigt · läuft bis 31.12.2026". */
function effective_status_label(array $contract): string
{
    if (($contract['status'] ?? 'active') === 'paused') {
        return 'Pausiert';
    }
    if (!empty($contract['cancelled_at'])) {
        return is_contract_running($contract)
            ? 'Gekündigt · läuft bis ' . format_date_de($contract['cancelled_at'])
            : 'Beendet seit ' . format_date_de($contract['cancelled_at']);
    }
    if (!empty($contract['cancellation_submitted_at'])) {
        return 'Kündigung eingereicht';
    }
    if ($contract['start_date'] > date('Y-m-d')) {
        return 'Startet am ' . format_date_de($contract['start_date']);
    }
    return 'Aktiv';
}

/** CSS-Badge-Klasse passend zu effective_status_label(). */
function effective_status_badge_class(array $contract): string
{
    if (($contract['status'] ?? 'active') === 'paused') {
        return 'badge-neutral';
    }
    if (!empty($contract['cancelled_at'])) {
        return is_contract_running($contract) ? 'badge-warn' : 'badge-expired';
    }
    if (!empty($contract['cancellation_submitted_at']) || $contract['start_date'] > date('Y-m-d')) {
        return 'badge-neutral';
    }
    return 'badge-ok';
}

/**
 * Text und Badge-Klasse für die Spalte "Kündbar" (Liste, Dashboard).
 *
 * @return array{text:string, class:string}
 */
function termination_badge(array $contract): array
{
    $d = $contract['deadline_info'] ?? calculate_contract_deadline($contract);
    switch ($d['state']) {
        case 'cancellable':
            return [
                'text'  => format_date_de($d['cancel_deadline']->format('Y-m-d')),
                'class' => 'badge-' . ($contract['urgency'] ?? deadline_urgency($d['days_left'])),
            ];
        case 'open':
            return ['text' => 'jederzeit kündbar', 'class' => 'badge-neutral'];
        case 'ends':
            return ['text' => 'endet ' . format_date_de($d['period_end']->format('Y-m-d')), 'class' => 'badge-neutral'];
        default:
            return ['text' => '–', 'class' => ''];
    }
}

// ------------------------------------------------------------------
// Verträge laden
// ------------------------------------------------------------------

/** SQL-Bedingung: nicht pausiert und noch nicht beendet (Alias `c` für contracts). */
const SQL_NOT_ENDED = "c.status != 'paused' AND (c.cancelled_at IS NULL OR c.cancelled_at >= CURDATE())";

/** Alle nicht pausierten, noch nicht beendeten Verträge inkl. Kündigungsinfos, nach Dringlichkeit sortiert. */
function get_contracts_with_deadlines(PDO $pdo): array
{
    $contracts = $pdo->query("SELECT c.*, cat.name AS category_name, cat.color_hex, cat.icon
                                FROM contracts c
                                LEFT JOIN categories cat ON cat.id = c.category_id
                               WHERE " . SQL_NOT_ENDED)->fetchAll();

    foreach ($contracts as &$c) {
        $c['deadline_info'] = calculate_contract_deadline($c);
        $c['urgency'] = deadline_urgency($c['deadline_info']['days_left'], REMINDER_WARN_DAYS, REMINDER_URGENT_DAYS);
    }
    unset($c);

    usort($contracts, function ($a, $b) {
        $da = $a['deadline_info']['days_left'];
        $db = $b['deadline_info']['days_left'];
        if ($da === null && $db === null) return strcasecmp($a['name'], $b['name']);
        if ($da === null) return 1;
        if ($db === null) return -1;
        return $da <=> $db;
    });

    return $contracts;
}

/** Eingereichte Kündigungen, für die noch keine Bestätigung des Anbieters erfasst ist. */
function get_pending_cancellation_confirmations(PDO $pdo): array
{
    $rows = $pdo->query("SELECT c.id, c.name, c.cancellation_submitted_at, c.cancelled_at, cat.color_hex
                           FROM contracts c
                           LEFT JOIN categories cat ON cat.id = c.category_id
                          WHERE c.cancellation_submitted_at IS NOT NULL
                            AND c.cancellation_confirmed_at IS NULL
                            AND (c.cancelled_at IS NULL OR c.cancelled_at >= CURDATE())
                          ORDER BY c.cancellation_submitted_at ASC")->fetchAll();

    $today = new DateTimeImmutable('today');
    foreach ($rows as &$r) {
        $r['days_waiting'] = (int) (new DateTimeImmutable($r['cancellation_submitted_at']))->diff($today)->format('%r%a');
        $r['overdue'] = $r['days_waiting'] >= CANCELLATION_CONFIRMATION_DAYS;
    }
    unset($r);
    return $rows;
}

// ------------------------------------------------------------------
// Kosten (Daten einmal laden, Berechnung in domain.php)
// ------------------------------------------------------------------

/**
 * Alle Verträge (mit Kategorie) und die komplette Kostenhistorie. Wird pro Request nur einmal geladen.
 *
 * @return array{contracts: array<int,array>, history: array<int,array<int,array>>}
 */
function load_cost_dataset(PDO $pdo): array
{
    static $cache = null;
    if ($cache === null) {
        $contracts = $pdo->query("SELECT c.*, cat.name AS category_name, cat.color_hex, cat.icon
                                    FROM contracts c
                                    LEFT JOIN categories cat ON cat.id = c.category_id")->fetchAll();
        $history = [];
        foreach ($pdo->query("SELECT id, contract_id, amount, valid_from, reason
                                FROM cost_history ORDER BY valid_from ASC, id ASC")->fetchAll() as $h) {
            $history[$h['contract_id']][] = $h;
        }
        $cache = ['contracts' => $contracts, 'history' => $history];
    }
    return $cache;
}

function today_string(): string
{
    return date('Y-m-d');
}

/** Beträge für die Anzeige runden (Berechnung bleibt ungerundet). */
function round_items(array $items): array
{
    foreach ($items as &$item) {
        $item['amount'] = round($item['amount'], 2);
    }
    return $items;
}

/** Laufende Verträge mit ihrem monatlichen Kostenanteil zu einem Stichtag (Standard: heute). */
function get_run_rate_items(PDO $pdo, ?string $date = null): array
{
    $data = load_cost_dataset($pdo);
    return run_rate_items($data['contracts'], $data['history'], $date ?? today_string());
}

/** Summe der aktuellen monatlichen Kosten aller laufenden Verträge. */
function get_current_monthly_total(PDO $pdo): float
{
    return month_cost_total(get_run_rate_items($pdo));
}

/**
 * Aktuelle monatliche Kosten im Vergleich zum Vormonatsende (gleiche Berechnung an zwei Stichtagen,
 * daher stimmen Kachel und Differenz immer überein).
 *
 * @return array{current:float, previous:float, diff:float, pct:?float}
 */
function get_month_over_month(PDO $pdo): array
{
    $current  = get_current_monthly_total($pdo);
    $prevDate = (new DateTimeImmutable('today'))->modify('last day of previous month')->format('Y-m-d');
    $previous = month_cost_total(get_run_rate_items($pdo, $prevDate));
    return [
        'current'  => $current,
        'previous' => $previous,
        'diff'     => round($current - $previous, 2),
        'pct'      => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null,
    ];
}

/** Hochrechnung der Jahreskosten: aktuelle monatliche Kosten × 12. */
function get_estimated_yearly_cost(PDO $pdo): float
{
    return round(get_current_monthly_total($pdo) * 12, 2);
}

/** Kosten nach Kategorie (aktuelle monatliche Kosten), absteigend. */
function get_category_breakdown(PDO $pdo): array
{
    $byCategory = [];
    foreach (get_run_rate_items($pdo) as $item) {
        if ($item['category_id'] === null) {
            continue;
        }
        $key = $item['category_id'];
        $byCategory[$key] ??= [
            'id' => $key, 'name' => $item['category_name'], 'color_hex' => $item['color_hex'],
            'icon' => $item['icon'], 'monthly_sum' => 0.0,
        ];
        $byCategory[$key]['monthly_sum'] += $item['amount'];
    }
    usort($byCategory, fn($a, $b) => $b['monthly_sum'] <=> $a['monthly_sum']);
    return $byCategory;
}

/**
 * Monatliche Gesamtkosten (periodisiert) der letzten $months Monate inkl. aktuellem Monat.
 *
 * @return array<string,float> Schlüssel "YYYY-MM"
 */
function get_monthly_spend_series(PDO $pdo, int $months = 24): array
{
    $data = load_cost_dataset($pdo);
    $to   = (new DateTimeImmutable('first day of this month'));
    $from = $to->modify('-' . ($months - 1) . ' months');
    return monthly_cost_series($data['contracts'], $data['history'], $from->format('Y-m'), $to->format('Y-m'), today_string());
}

/**
 * Jahressummen aller Kalenderjahre ab dem ersten Vertragsjahr. Das laufende Jahr enthält nur die
 * bereits angefangenen Monate ('months' < 12, 'complete' = false).
 *
 * @return array<string,array{total:float, months:int, complete:bool}>
 */
function get_yearly_series(PDO $pdo): array
{
    $data = load_cost_dataset($pdo);
    $series = [];
    foreach (array_reverse(get_available_years($pdo)) as $year) {
        $b = year_cost_breakdown($data['contracts'], $data['history'], (string) $year, today_string());
        $series[(string) $year] = ['total' => $b['total'], 'months' => $b['months'], 'complete' => $b['months'] === 12];
    }
    return $series;
}

/**
 * Kostenaufstellung je Vertrag für einen Monat ("YYYY-MM").
 *
 * @return array{total: float, items: array<int,array>}
 */
function get_month_breakdown(PDO $pdo, string $ym): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) {
        return ['total' => 0.0, 'items' => []];
    }
    $data  = load_cost_dataset($pdo);
    $items = month_cost_items($data['contracts'], $data['history'], $ym, today_string());
    return ['total' => month_cost_total($items), 'items' => round_items($items)];
}

/**
 * Kostenaufstellung je Vertrag für ein Kalenderjahr (im laufenden Jahr bis einschließlich aktuellem Monat).
 *
 * @return array{total: float, months: int, items: array<int,array>}
 */
function get_year_breakdown(PDO $pdo, string $year): array
{
    $data = load_cost_dataset($pdo);
    return year_cost_breakdown($data['contracts'], $data['history'], $year, today_string());
}

/** Alle Jahre, für die Vertragsdaten existieren (neueste zuerst, für Auswahlfelder). */
function get_available_years(PDO $pdo): array
{
    $first = $pdo->query("SELECT MIN(YEAR(d)) FROM (SELECT start_date AS d FROM contracts
                                                    UNION ALL SELECT valid_from FROM cost_history) x")->fetchColumn();
    $maxYear = (int) date('Y');
    $minYear = $first ? min((int) $first, $maxYear) : $maxYear;
    return range($maxYear, $minYear);
}

// ------------------------------------------------------------------
// Zahlungskalender
// ------------------------------------------------------------------

/**
 * Abbuchungen im Zeitraum, gruppiert je Monat.
 *
 * @return array<string,array{total:float, payments:array}> Schlüssel "YYYY-MM", lückenlos über den Zeitraum
 */
function get_payment_calendar(PDO $pdo, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $data = load_cost_dataset($pdo);
    $payments = payments_in_range($data['contracts'], $data['history'], $from, $to, today_string());

    $months = [];
    $cursor = $from->modify('first day of this month');
    while ($cursor <= $to) {
        $months[$cursor->format('Y-m')] = ['total' => 0.0, 'payments' => []];
        $cursor = $cursor->modify('+1 month');
    }
    foreach ($payments as $p) {
        $key = substr($p['date'], 0, 7);
        $months[$key]['payments'][] = $p;
        $months[$key]['total'] += $p['amount'];
    }
    foreach ($months as &$m) {
        $m['total'] = round($m['total'], 2);
    }
    return $months;
}

/** Tatsächliche Abbuchungen je Vertrag in einem Kalenderjahr (Basis des Zahlungs-Exports). */
function get_year_payments(PDO $pdo, string $year): array
{
    $data = load_cost_dataset($pdo);
    $payments = payments_in_range($data['contracts'], $data['history'],
        new DateTimeImmutable($year . '-01-01'), new DateTimeImmutable($year . '-12-31'), today_string());

    $perContract = [];
    foreach ($payments as $p) {
        $perContract[$p['id']] ??= $p + ['count' => 0, 'sum' => 0.0];
        $perContract[$p['id']]['count']++;
        $perContract[$p['id']]['sum'] += $p['amount'];
    }
    $items = array_values($perContract);
    usort($items, fn($a, $b) => $b['sum'] <=> $a['sum']);
    return ['total' => round(array_sum(array_column($items, 'sum')), 2), 'items' => $items];
}

// ------------------------------------------------------------------
// Preisentwicklung
// ------------------------------------------------------------------

/**
 * Preisänderungen aller Verträge.
 *
 * @return array{
 *   contracts: list<array>,  // je Vertrag mit Änderungen (Startpreis, aktueller Preis, Verlauf)
 *   changes: list<array>,    // alle Änderungen chronologisch absteigend
 *   recent_increases: list<array>,
 *   like_for_like: array{from:string, total_from:float, total_to:float, diff:float, pct:?float, contracts:int}
 * }
 */
function get_price_overview(PDO $pdo, int $compareMonths = 12): array
{
    $data     = load_cost_dataset($pdo);
    $todayStr = today_string();
    $today    = new DateTimeImmutable($todayStr);
    $flagFrom = $today->modify('-' . PRICE_INCREASE_FLAG_DAYS . ' days')->format('Y-m-d');

    $contractsOut = [];
    $allChanges = [];
    $recent = [];

    foreach ($data['contracts'] as $c) {
        $history = $data['history'][$c['id']] ?? [];
        if (!$history) {
            continue;
        }
        $changes = history_changes($history);
        $first   = (float) $history[0]['amount'];
        $current = price_at($history, $todayStr) ?? $first;
        $increases = count(array_filter($changes, fn($ch) => $ch['direction'] === 'up'));

        $totalPct = $first > 0 ? ($current - $first) / $first * 100 : null;
        $years = (new DateTimeImmutable($history[0]['valid_from']))->diff($today)->days / 365.25;
        $perYear = ($totalPct !== null && $years >= 1 && $current > 0 && $first > 0)
            ? (pow($current / $first, 1 / $years) - 1) * 100 : null;

        $ended = !empty($c['cancelled_at']) && $c['cancelled_at'] < $todayStr;
        $contractsOut[] = [
            'id' => (int) $c['id'], 'name' => $c['name'], 'billing_cycle' => $c['billing_cycle'],
            'color_hex' => $c['color_hex'] ?? '#8E8E93', 'ended' => $ended,
            'first' => $first, 'current' => $current, 'since' => $history[0]['valid_from'],
            'pct' => $totalPct !== null ? round($totalPct, 1) : null,
            'pct_per_year' => $perYear !== null ? round($perYear, 1) : null,
            'increases' => $increases, 'changes' => $changes,
            'last_change' => $changes ? end($changes)['date'] : null,
        ];

        foreach ($changes as $ch) {
            $monthlyDelta = to_monthly_amount($ch['delta'], $c['billing_cycle']);
            $row = $ch + [
                'contract_id' => (int) $c['id'], 'contract_name' => $c['name'],
                'billing_cycle' => $c['billing_cycle'], 'monthly_delta' => round($monthlyDelta, 2),
            ];
            $allChanges[] = $row;
            if ($ch['direction'] === 'up' && $ch['date'] >= $flagFrom && $ch['date'] <= $todayStr
                && !$ended && ($c['status'] ?? 'active') !== 'paused') {
                $recent[] = $row;
            }
        }
    }

    usort($allChanges, fn($a, $b) => [$b['date'], $a['contract_name']] <=> [$a['date'], $b['contract_name']]);
    usort($recent, fn($a, $b) => $b['date'] <=> $a['date']);
    usort($contractsOut, fn($a, $b) => ($b['pct'] ?? -INF) <=> ($a['pct'] ?? -INF));

    // Preisentwicklung "wie gehabt": nur Verträge, die zu beiden Stichtagen liefen
    $fromDate = $today->modify("-{$compareMonths} months")->format('Y-m-d');
    $totalFrom = $totalTo = 0.0;
    $count = 0;
    foreach ($data['contracts'] as $c) {
        if (($c['status'] ?? 'active') === 'paused'
            || !contract_runs_on($c, $fromDate) || !contract_runs_on($c, $todayStr)) {
            continue;
        }
        $history = $data['history'][$c['id']] ?? [];
        $pFrom = price_at($history, $fromDate);
        $pTo   = price_at($history, $todayStr);
        if ($pFrom === null || $pTo === null) {
            continue;
        }
        $totalFrom += to_monthly_amount($pFrom, $c['billing_cycle']);
        $totalTo   += to_monthly_amount($pTo, $c['billing_cycle']);
        $count++;
    }

    return [
        'contracts' => $contractsOut,
        'changes'   => $allChanges,
        'recent_increases' => $recent,
        'like_for_like' => [
            'from' => $fromDate, 'months' => $compareMonths, 'contracts' => $count,
            'total_from' => round($totalFrom, 2), 'total_to' => round($totalTo, 2),
            'diff' => round($totalTo - $totalFrom, 2),
            'pct'  => $totalFrom > 0 ? round(($totalTo - $totalFrom) / $totalFrom * 100, 1) : null,
        ],
    ];
}

// ------------------------------------------------------------------
// Formatierung
// ------------------------------------------------------------------

function format_eur(float $amount): string
{
    return number_format($amount, 2, ',', '.') . ' €';
}

/** Prozentwert mit Vorzeichen, z. B. "+4,5 %". */
function format_pct(?float $pct): string
{
    if ($pct === null) return '–';
    return ($pct > 0 ? '+' : '') . number_format($pct, 1, ',', '.') . ' %';
}

/** Ausgeschriebener deutscher Monatsname, z. B. "Juli 2026". */
function format_month_long_de(string $ym): string
{
    $months = [
        'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
    ];
    try {
        $d = new DateTimeImmutable($ym . '-01');
    } catch (Exception) {
        return $ym;
    }
    return $months[(int) $d->format('n') - 1] . ' ' . $d->format('Y');
}

/** Kurzer deutscher Monatsname, z. B. "Jan 25". */
function format_month_de(string $ymFirstDay): string
{
    $months = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
    $d = new DateTimeImmutable($ymFirstDay);
    return $months[(int) $d->format('n') - 1] . ' ' . $d->format('y');
}

function format_date_de(?string $date): string
{
    if (!$date) return '–';
    try {
        return (new DateTimeImmutable($date))->format('d.m.Y');
    } catch (Exception) {
        return '–';
    }
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024, 0, ',', '.') . ' KB';
    return $bytes . ' B';
}

// ------------------------------------------------------------------
// CSV
// ------------------------------------------------------------------

/**
 * Streamt eine CSV-Datei als Download. Excel-kompatibel: UTF-8-BOM,
 * Semikolon als Trennzeichen (Komma ist in DE der Dezimaltrenner).
 */
function send_csv(string $filename, array $header, iterable $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: public');
    header('Cache-Control: private');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM, damit Excel Umlaute korrekt anzeigt
    fputcsv($out, $header, ';');
    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
}

/** Zahl im deutschen CSV/Excel-Format (Komma als Dezimaltrenner, kein Tausendertrenner). */
function csv_amount(float $value): string
{
    return number_format($value, 2, ',', '');
}

// ------------------------------------------------------------------
// Sonstiges
// ------------------------------------------------------------------

/**
 * Hängt an einen lokalen asset-Pfad (CSS/JS) einen Cache-Busting-Parameter an (Änderungszeitstempel),
 * damit der Browser nach einem Deployment die neue Version lädt.
 */
function asset_url(string $relativePath): string
{
    $fullPath = __DIR__ . '/../' . $relativePath;
    $v = is_file($fullPath) ? filemtime($fullPath) : time();
    return $relativePath . '?v=' . $v;
}

function e(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/** Einmalige Meldung für den nächsten Seitenaufruf (Post/Redirect/Get). */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Holt und löscht die vorgemerkten Meldungen. */
function pull_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}
