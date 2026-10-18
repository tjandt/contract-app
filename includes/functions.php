<?php
/**
 * Zentrale Hilfsfunktionen für Laufzeit-, Kündigungs- und Kostenberechnungen.
 */

/** Wandelt einen Betrag je nach Abrechnungszyklus in den Monatswert um. */
function to_monthly_amount(float $amount, string $cycle): float
{
    return match ($cycle) {
        'yearly'    => $amount / 12,
        'quarterly' => $amount / 3,
        default     => $amount, // monthly
    };
}

function cycle_label(string $cycle): string
{
    return match ($cycle) {
        'yearly'    => 'jährlich',
        'quarterly' => 'vierteljährlich',
        default     => 'monatlich',
    };
}

function status_label(string $status): string
{
    return match ($status) {
        'cancelled' => 'Gekündigt',
        'paused'    => 'Pausiert',
        default     => 'Aktiv',
    };
}

/**
 * Ein Vertrag "läuft" (und zählt zu den aktuellen Kosten), solange er nicht
 * pausiert ist und sein Kündigungsdatum (falls gesetzt) noch nicht erreicht wurde.
 * So fließen bereits gekündigte, aber noch laufende Verträge korrekt in die
 * aktuellen Kosten ein und fallen automatisch zum Stichtag heraus.
 */
function is_contract_running(array $contract, ?DateTimeImmutable $today = null): bool
{
    if (($contract['status'] ?? 'active') === 'paused') {
        return false;
    }
    if (empty($contract['cancelled_at'])) {
        return true;
    }
    $today = $today ?? new DateTimeImmutable('today');
    try {
        return new DateTimeImmutable($contract['cancelled_at']) >= $today;
    } catch (Exception) {
        return true;
    }
}

/** Sprechender Status inkl. Kündigungsdatum, z.B. "Gekündigt · läuft bis 31.12.2026". */
function effective_status_label(array $contract): string
{
    if (($contract['status'] ?? 'active') === 'paused') {
        return 'Pausiert';
    }
    if (!empty($contract['cancelled_at'])) {
        $running = is_contract_running($contract);
        return $running
            ? 'Gekündigt · läuft bis ' . format_date_de($contract['cancelled_at'])
            : 'Beendet seit ' . format_date_de($contract['cancelled_at']);
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
    return 'badge-ok';
}

/**
 * Berechnet für einen Vertrag das Ende der aktuellen Laufzeitperiode sowie
 * die letztmögliche/nächste Kündigungsfrist unter Berücksichtigung von
 * automatischer Verlängerung (renewal_term_months).
 *
 * @return array{period_end: ?DateTimeImmutable, cancel_deadline: ?DateTimeImmutable, days_left: ?int, auto_renews: bool}
 */
function calculate_contract_deadline(array $contract): array
{
    // Kündigungsfrist ist nur relevant, solange noch keine Kündigung erfasst wurde
    // und der Vertrag nicht pausiert ist.
    if (($contract['status'] ?? 'active') === 'paused' || !empty($contract['cancelled_at'])) {
        return ['period_end' => null, 'cancel_deadline' => null, 'days_left' => null, 'auto_renews' => false];
    }

    try {
        $start = new DateTimeImmutable($contract['start_date']);
    } catch (Exception) {
        return ['period_end' => null, 'cancel_deadline' => null, 'days_left' => null, 'auto_renews' => false];
    }

    $today = new DateTimeImmutable('today');
    $initialMonths = max(0, (int) $contract['initial_term_months']);
    $renewalMonths = max(0, (int) $contract['renewal_term_months']);
    $noticeDays    = max(0, (int) $contract['notice_period_days']);

    $periodEnd = $start->modify("+{$initialMonths} months");

    $autoRenews = $renewalMonths > 0;

    // Falls die Erstlaufzeit bereits vorbei ist, in Verlängerungsschritten weiterspringen,
    // bis wir die aktuell laufende Periode gefunden haben.
    if ($autoRenews) {
        $guard = 0;
        while ($periodEnd <= $today && $guard < 2000) {
            $periodEnd = $periodEnd->modify("+{$renewalMonths} months");
            $guard++;
        }
    }

    $cancelDeadline = $periodEnd->modify("-{$noticeDays} days");

    // Kein Auto-Renewal und Erstlaufzeit bereits abgelaufen -> jederzeit mit Frist kündbar
    if (!$autoRenews && $periodEnd <= $today) {
        $cancelDeadline = $today;
    }

    $daysLeft = (int) $today->diff($cancelDeadline)->format('%r%a');

    return [
        'period_end'      => $periodEnd,
        'cancel_deadline' => $cancelDeadline,
        'days_left'       => $daysLeft,
        'auto_renews'     => $autoRenews,
    ];
}

/** Liefert eine kurze, sprechende Reminder-Stufe: 'urgent' | 'warn' | 'ok' | null */
function deadline_urgency(?int $daysLeft): ?string
{
    if ($daysLeft === null) {
        return null;
    }
    if ($daysLeft < 0) {
        return 'expired';
    }
    if ($daysLeft <= REMINDER_URGENT_DAYS) {
        return 'urgent';
    }
    if ($daysLeft <= REMINDER_WARN_DAYS) {
        return 'warn';
    }
    return 'ok';
}

/** Alle aktuell laufenden Verträge inkl. berechneter Kündigungsinfos, sortiert nach Dringlichkeit. */
function get_contracts_with_deadlines(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT c.*, cat.name AS category_name, cat.color_hex, cat.icon
                          FROM contracts c
                          LEFT JOIN categories cat ON cat.id = c.category_id
                          WHERE c.status != 'paused'
                            AND (c.cancelled_at IS NULL OR c.cancelled_at >= CURDATE())");
    $contracts = $stmt->fetchAll();

    foreach ($contracts as &$c) {
        $deadline = calculate_contract_deadline($c);
        $c['deadline_info'] = $deadline;
        $c['urgency'] = deadline_urgency($deadline['days_left']);
    }
    unset($c);

    usort($contracts, function ($a, $b) {
        $da = $a['deadline_info']['days_left'];
        $db = $b['deadline_info']['days_left'];
        if ($da === null) return 1;
        if ($db === null) return -1;
        return $da <=> $db;
    });

    return $contracts;
}

/** Summe der aktuellen monatlichen Kosten aller aktuell laufenden Verträge
 *  (inkl. bereits gekündigter Verträge, die bis zu ihrem Kündigungsdatum noch laufen). */
function get_current_monthly_total(PDO $pdo): float
{
    $stmt = $pdo->query("SELECT current_cost, billing_cycle FROM contracts
                          WHERE status != 'paused'
                            AND (cancelled_at IS NULL OR cancelled_at >= CURDATE())");
    $total = 0.0;
    foreach ($stmt->fetchAll() as $row) {
        $total += to_monthly_amount((float) $row['current_cost'], $row['billing_cycle']);
    }
    return $total;
}

/**
 * Ermittelt für jeden Monat im gegebenen Zeitraum die Gesamtkosten aller
 * zu diesem Zeitpunkt aktiven Verträge, basierend auf der Kostenhistorie
 * (jeweils der zuletzt gültige Preis vor/an dem Monatsende).
 *
 * @return array<string,float> Schlüssel im Format "YYYY-MM"
 */
function get_monthly_spend_series(PDO $pdo, int $months = 24): array
{
    $end = new DateTimeImmutable('first day of this month');
    $start = $end->modify('-' . ($months - 1) . ' months');

    // Alle Vertragsdaten + komplette Kostenhistorie einmalig laden (statt pro Monat neu zu queryen)
    $contracts = $pdo->query("SELECT id, start_date, cancelled_at, billing_cycle FROM contracts")->fetchAll();
    $historyStmt = $pdo->query("SELECT contract_id, amount, valid_from FROM cost_history ORDER BY valid_from ASC");
    $historyByContract = [];
    foreach ($historyStmt->fetchAll() as $h) {
        $historyByContract[$h['contract_id']][] = $h;
    }

    $series = [];
    $cursor = $start;
    for ($i = 0; $i < $months; $i++) {
        $monthEnd = $cursor->modify('last day of this month');
        $key = $cursor->format('Y-m');
        $total = 0.0;

        foreach ($contracts as $c) {
            $startDate = new DateTimeImmutable($c['start_date']);
            if ($startDate > $monthEnd) {
                continue; // Vertrag existierte in diesem Monat noch nicht
            }
            if (!empty($c['cancelled_at'])) {
                $cancelledAt = new DateTimeImmutable($c['cancelled_at']);
                if ($cancelledAt < $cursor) {
                    continue; // Vertrag war in diesem Monat bereits gekündigt
                }
            }

            $contractHistory = $historyByContract[$c['id']] ?? [];
            $price = null;
            foreach ($contractHistory as $h) {
                if (new DateTimeImmutable($h['valid_from']) <= $monthEnd) {
                    $price = (float) $h['amount'];
                } else {
                    break;
                }
            }
            if ($price === null && !empty($contractHistory)) {
                // Kein Preis-Eintrag vor diesem Monat gefunden (z.B. weil der
                // Vertragsbeginn nachträglich vordatiert wurde) -> ältesten
                // bekannten Preis als Näherung verwenden, statt den Vertrag
                // für diesen Monat komplett wegzulassen.
                $price = (float) $contractHistory[0]['amount'];
            }
            if ($price !== null) {
                $total += to_monthly_amount($price, $c['billing_cycle']);
            }
        }

        $series[$key] = round($total, 2);
        $cursor = $cursor->modify('+1 month');
    }

    return $series;
}

/**
 * Kostenaufstellung je Vertrag für einen einzelnen Monat (Drilldown-Ansicht
 * zum Anklicken eines Punktes/Balkens im Kostenverlauf-Diagramm).
 * Nutzt dieselbe Logik wie get_monthly_spend_series(), aber für einen Monat
 * und mit Vertragsdetails statt nur der Summe.
 *
 * @param string $ym Monat im Format "YYYY-MM"
 * @return array{total: float, items: array<int,array>}
 */
function get_month_breakdown(PDO $pdo, string $ym): array
{
    try {
        $cursor = new DateTimeImmutable($ym . '-01');
    } catch (Exception) {
        return ['total' => 0.0, 'items' => []];
    }
    $monthEnd = $cursor->modify('last day of this month');

    $contracts = $pdo->query("SELECT c.id, c.name, c.start_date, c.cancelled_at, c.billing_cycle,
                                      cat.name AS category_name, cat.color_hex, cat.icon
                               FROM contracts c
                               LEFT JOIN categories cat ON cat.id = c.category_id")->fetchAll();

    $historyStmt = $pdo->query("SELECT contract_id, amount, valid_from FROM cost_history ORDER BY valid_from ASC");
    $historyByContract = [];
    foreach ($historyStmt->fetchAll() as $h) {
        $historyByContract[$h['contract_id']][] = $h;
    }

    $items = [];
    $total = 0.0;

    foreach ($contracts as $c) {
        $startDate = new DateTimeImmutable($c['start_date']);
        if ($startDate > $monthEnd) {
            continue;
        }
        if (!empty($c['cancelled_at'])) {
            $cancelledAt = new DateTimeImmutable($c['cancelled_at']);
            if ($cancelledAt < $cursor) {
                continue;
            }
        }

        $contractHistory = $historyByContract[$c['id']] ?? [];
        $price = null;
        foreach ($contractHistory as $h) {
            if (new DateTimeImmutable($h['valid_from']) <= $monthEnd) {
                $price = (float) $h['amount'];
            } else {
                break;
            }
        }
        if ($price === null && !empty($contractHistory)) {
            // Siehe get_monthly_spend_series(): ältesten bekannten Preis als
            // Näherung verwenden, statt den Vertrag wegzulassen.
            $price = (float) $contractHistory[0]['amount'];
        }
        if ($price === null) {
            continue;
        }

        $monthly = to_monthly_amount($price, $c['billing_cycle']);
        $total += $monthly;

        $items[] = [
            'id'            => (int) $c['id'],
            'name'          => $c['name'],
            'category_name' => $c['category_name'],
            'color_hex'     => $c['color_hex'] ?? '#8E8E93',
            'icon'          => $c['icon'] ?? '📄',
            'amount'        => round($monthly, 2),
        ];
    }

    usort($items, fn($a, $b) => $b['amount'] <=> $a['amount']);

    return ['total' => round($total, 2), 'items' => $items];
}

/**
 * Kostenaufstellung je Vertrag für ein komplettes Kalenderjahr (Jahres-Drilldown
 * und Basis für den CSV-Export "Jahresübersicht").
 *
 * @return array{total: float, items: array<int,array>}
 */
function get_year_breakdown(PDO $pdo, string $year): array
{
    $totals = [];
    for ($m = 1; $m <= 12; $m++) {
        $ym = $year . '-' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
        $monthBreakdown = get_month_breakdown($pdo, $ym);
        foreach ($monthBreakdown['items'] as $item) {
            if (!isset($totals[$item['id']])) {
                $totals[$item['id']] = $item;
                $totals[$item['id']]['amount'] = 0.0;
            }
            $totals[$item['id']]['amount'] += $item['amount'];
        }
    }

    $items = array_values($totals);
    foreach ($items as &$item) {
        $item['amount'] = round($item['amount'], 2);
    }
    unset($item);

    usort($items, fn($a, $b) => $b['amount'] <=> $a['amount']);

    $total = round(array_sum(array_column($items, 'amount')), 2);

    return ['total' => $total, 'items' => $items];
}

/** Erste und letzte Jahreszahl, für die überhaupt Vertragsdaten existieren (für Jahres-Auswahlfelder). */
function get_available_years(PDO $pdo): array
{
    $minYear = (int) ($pdo->query("SELECT YEAR(MIN(start_date)) FROM contracts")->fetchColumn() ?: date('Y'));
    $maxYear = (int) date('Y');
    if ($minYear > $maxYear) $minYear = $maxYear;
    return range($maxYear, $minYear); // neueste zuerst
}

/** Fasst eine Monatsreihe ("YYYY-MM" => Betrag) zu Jahressummen zusammen. */
function aggregate_yearly(array $monthlySeries): array
{
    $years = [];
    foreach ($monthlySeries as $ym => $amount) {
        $year = substr($ym, 0, 4);
        $years[$year] = ($years[$year] ?? 0) + $amount;
    }
    return $years;
}

/** Hochrechnung der geschätzten Jahreskosten auf Basis der aktuell aktiven Verträge. */
function get_estimated_yearly_cost(PDO $pdo): float
{
    return round(get_current_monthly_total($pdo) * 12, 2);
}

function format_eur(float $amount): string
{
    return number_format($amount, 2, ',', '.') . ' €';
}

/** Ausgeschriebener deutscher Monatsname, z.B. "Juli 2026" */
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

/** Kurzer deutscher Monatsname, z.B. "Jan 25" */
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

/**
 * Hängt an einen lokalen asset-Pfad (CSS/JS) automatisch einen Cache-Busting-
 * Parameter an, basierend auf dem Änderungszeitstempel der Datei. So lädt der
 * Browser nach einem Deployment zuverlässig die neue Version statt eine alte,
 * zwischengespeicherte Datei weiterzuverwenden.
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
