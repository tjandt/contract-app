<?php
/**
 * Reine Domain-Logik: Laufzeiten, Kündigungsfristen, Preise, Zahlungstermine.
 *
 * Diese Datei greift weder auf Datenbank, Session noch Request zu. Alles, was vom
 * heutigen Datum abhängt, bekommt "$today" als Parameter – dadurch ist die Logik
 * deterministisch testbar (siehe tests/run.php).
 *
 * Datumswerte werden als 'Y-m-d'-Strings gespeichert und verglichen; die ISO-Schreibweise
 * sortiert lexikographisch korrekt und spart in Schleifen teure DateTime-Objekte.
 */

const NOTICE_UNITS    = ['days', 'months'];
const END_ALIGNMENTS  = ['none', 'month_end', 'quarter_end', 'year_end'];
const BILLING_CYCLES  = ['monthly', 'quarterly', 'yearly'];

// ------------------------------------------------------------------
// Beträge & Abrechnungszyklen
// ------------------------------------------------------------------

/** Anzahl Monate zwischen zwei Abbuchungen. */
function cycle_months(string $cycle): int
{
    return match ($cycle) {
        'yearly'    => 12,
        'quarterly' => 3,
        default     => 1,
    };
}

/** Wandelt einen Betrag je nach Abrechnungszyklus in den Monatswert um. */
function to_monthly_amount(float $amount, string $cycle): float
{
    return $amount / cycle_months($cycle);
}

// ------------------------------------------------------------------
// Datumsarithmetik
// ------------------------------------------------------------------

/**
 * Addiert Monate, ohne in den Folgemonat zu "überlaufen" (31.08. + 6 Monate = 28.02.,
 * nicht 03.03. wie bei DateTime::modify('+6 months')).
 */
function add_months_clamped(DateTimeImmutable $date, int $months): DateTimeImmutable
{
    $day    = (int) $date->format('j');
    $target = $date->modify('first day of this month')->modify(sprintf('%+d months', $months));
    $day    = min($day, (int) $target->format('t'));
    return $target->setDate((int) $target->format('Y'), (int) $target->format('n'), $day);
}

function is_month_end(DateTimeImmutable $date): bool
{
    return $date->format('j') === $date->format('t');
}

/**
 * Letzter Tag einer Frist von $months Monaten, die am $start beginnt (§ 188 BGB):
 * 01.01.2025 + 12 Monate endet am 31.12.2025. Fehlt der Starttag im Zielmonat
 * (31.08. + 6 Monate), endet die Frist am Monatsletzten (28.02.).
 */
function term_last_day(DateTimeImmutable $start, int $months): DateTimeImmutable
{
    if ($months <= 0) {
        return $start->modify('-1 day');
    }
    $target = add_months_clamped($start, $months);
    if ((int) $start->format('j') > (int) $target->format('j')) {
        return $target;
    }
    return $target->modify('-1 day');
}

/** Rundet ein Vertragsende auf Monats-/Quartals-/Jahresende auf (Vertragsklausel "zum Monatsende" etc.). */
function align_term_end(DateTimeImmutable $lastDay, string $alignment): DateTimeImmutable
{
    switch ($alignment) {
        case 'month_end':
            return $lastDay->modify('last day of this month');
        case 'quarter_end':
            $quarterMonth = (int) (ceil((int) $lastDay->format('n') / 3) * 3);
            return $lastDay->setDate((int) $lastDay->format('Y'), $quarterMonth, 1)->modify('last day of this month');
        case 'year_end':
            return $lastDay->setDate((int) $lastDay->format('Y'), 12, 31);
        default:
            return $lastDay;
    }
}

/**
 * Letzter Tag, an dem die Kündigung zugehen muss, damit der Vertrag zum $endDay endet.
 * Tage: $endDay - N Tage. Monate: $endDay - N Monate (liegt $endDay auf einem Monatsende,
 * gilt auch das Ergebnis als Monatsende, "3 Monate zum Monatsende").
 */
function notice_deadline(DateTimeImmutable $endDay, int $value, string $unit): DateTimeImmutable
{
    if ($unit !== 'months') {
        return $endDay->modify('-' . max(0, $value) . ' days');
    }
    $result = add_months_clamped($endDay, -max(0, $value));
    return is_month_end($endDay) ? $result->modify('last day of this month') : $result;
}

/** Ende der Kündigungsfrist, wenn heute gekündigt wird (für unbefristet laufende Verträge). */
function notice_end_from(DateTimeImmutable $date, int $value, string $unit): DateTimeImmutable
{
    return $unit === 'months'
        ? add_months_clamped($date, max(0, $value))
        : $date->modify('+' . max(0, $value) . ' days');
}

// ------------------------------------------------------------------
// Laufzeit & Kündigung
// ------------------------------------------------------------------

/** Normalisierte Laufzeit-Parameter eines Vertrags (mit Defaults für ältere Datensätze). */
function contract_terms(array $contract): array
{
    $unit      = $contract['notice_period_unit'] ?? 'days';
    $alignment = $contract['end_alignment'] ?? 'none';
    return [
        'start'     => new DateTimeImmutable($contract['start_date']),
        'initial'   => max(0, (int) $contract['initial_term_months']),
        'renewal'   => max(0, (int) $contract['renewal_term_months']),
        'notice'    => max(0, (int) $contract['notice_period_days']), // Wert; Einheit siehe 'unit'
        'unit'      => in_array($unit, NOTICE_UNITS, true) ? $unit : 'days',
        'alignment' => in_array($alignment, END_ALIGNMENTS, true) ? $alignment : 'none',
    ];
}

/**
 * Sucht ab $asOf den nächsten Zeitpunkt, zu dem der Vertrag beendet werden kann.
 *
 * Zustände ('state'):
 *  - 'cancellable': Vertrag verlängert sich automatisch. period_end = Ende der Periode, die
 *                   noch fristgerecht gekündigt werden kann, cancel_deadline = letzter Kündigungstag.
 *                   Ist die Frist der aktuellen Periode schon verstrichen, verlängert sich der Vertrag
 *                   – dann zeigt das Ergebnis die nächste erreichbare Periode, und
 *                   locked_period_end nennt das Ende der Periode, die nicht mehr kündbar ist.
 *  - 'ends':        Keine Verlängerung, Erstlaufzeit läuft noch: der Vertrag endet am period_end von selbst.
 *  - 'open':        Keine Verlängerung, Erstlaufzeit vorbei: Vertrag läuft unbefristet und ist jederzeit
 *                   kündbar. earliest_end = Vertragsende, wenn ab $asOf gekündigt wird.
 *
 * @return array{state:string, period_end:?DateTimeImmutable, cancel_deadline:?DateTimeImmutable,
 *               auto_renews:bool, locked_period_end:?DateTimeImmutable, earliest_end:?DateTimeImmutable}
 */
function find_next_termination(array $contract, DateTimeImmutable $asOf): array
{
    $t = contract_terms($contract);
    $periodEnd = fn(int $k): DateTimeImmutable =>
        align_term_end(term_last_day($t['start'], $t['initial'] + $k * $t['renewal']), $t['alignment']);

    $result = [
        'state' => 'open', 'period_end' => null, 'cancel_deadline' => null,
        'auto_renews' => $t['renewal'] > 0, 'locked_period_end' => null, 'earliest_end' => null,
    ];

    if ($t['renewal'] === 0) {
        $end = $periodEnd(0);
        if ($end >= $asOf) {
            $result['state'] = 'ends';
            $result['period_end'] = $end;
        } else {
            $result['earliest_end'] = align_term_end(notice_end_from($asOf, $t['notice'], $t['unit']), $t['alignment']);
        }
        return $result;
    }

    $locked = null;
    for ($k = 0; $k < 5000; $k++) {
        $end = $periodEnd($k);
        if ($end < $asOf) {
            continue;
        }
        $deadline = notice_deadline($end, $t['notice'], $t['unit']);
        if ($deadline >= $asOf) {
            $result['state'] = 'cancellable';
            $result['period_end'] = $end;
            $result['cancel_deadline'] = $deadline;
            $result['locked_period_end'] = $locked;
            return $result;
        }
        $locked ??= $end; // Frist verstrichen -> Vertrag verlängert sich automatisch
    }
    return $result;
}

/** Vertrag ist gekündigt/ausgeschieden, sobald ein Kündigungsdatum oder ein Einreichungsdatum erfasst ist. */
function is_cancellation_recorded(array $contract): bool
{
    return !empty($contract['cancelled_at']) || !empty($contract['cancellation_submitted_at']);
}

/**
 * Berechnet das Vertragsende für eine Kündigung, die am $submittedAt eingereicht wurde
 * (nächstes erreichbares Periodenende bzw. Ende der Kündigungsfrist bei unbefristeten Verträgen).
 */
function compute_termination_end(array $contract, DateTimeImmutable $submittedAt): ?DateTimeImmutable
{
    $next = find_next_termination($contract, $submittedAt);
    return $next['state'] === 'open' ? $next['earliest_end'] : $next['period_end'];
}

/**
 * Kündigungsinformationen für den Reminder (nur für Verträge ohne erfasste Kündigung, nicht pausiert).
 *
 * @return array{state:string, period_end:?DateTimeImmutable, cancel_deadline:?DateTimeImmutable, days_left:?int,
 *               auto_renews:bool, locked_period_end:?DateTimeImmutable, earliest_end:?DateTimeImmutable}
 */
function calculate_contract_deadline(array $contract, ?DateTimeImmutable $today = null): array
{
    $none = [
        'state' => 'none', 'period_end' => null, 'cancel_deadline' => null, 'days_left' => null,
        'auto_renews' => false, 'locked_period_end' => null, 'earliest_end' => null,
    ];
    if (($contract['status'] ?? 'active') === 'paused' || is_cancellation_recorded($contract)) {
        return $none;
    }
    try {
        $today ??= new DateTimeImmutable('today');
        $next = find_next_termination($contract, $today);
    } catch (Exception) {
        return $none;
    }
    $next['days_left'] = $next['cancel_deadline']
        ? (int) $today->diff($next['cancel_deadline'])->format('%r%a')
        : null;
    return $next;
}

/** Reminder-Stufe: 'urgent' | 'warn' | 'ok' | 'expired' | null */
function deadline_urgency(?int $daysLeft, int $warnDays = 90, int $urgentDays = 30): ?string
{
    if ($daysLeft === null) {
        return null;
    }
    if ($daysLeft < 0) {
        return 'expired';
    }
    if ($daysLeft <= $urgentDays) {
        return 'urgent';
    }
    if ($daysLeft <= $warnDays) {
        return 'warn';
    }
    return 'ok';
}

/** Liegt $date innerhalb der Vertragslaufzeit (Beginn erreicht, Kündigungsdatum nicht überschritten)? */
function contract_runs_on(array $contract, string $date): bool
{
    if ($contract['start_date'] > $date) {
        return false;
    }
    return empty($contract['cancelled_at']) || $contract['cancelled_at'] >= $date;
}

// ------------------------------------------------------------------
// Preise (Kostenhistorie)
// ------------------------------------------------------------------

/**
 * Preis je Abrechnungszyklus, der an $date gültig ist: letzter Eintrag mit valid_from <= $date.
 * Gibt es keinen (z. B. Vertragsbeginn nachträglich vordatiert), dient der älteste bekannte Preis
 * als Näherung, statt den Vertrag wegzulassen.
 *
 * @param array<int,array{amount:mixed,valid_from:string}> $history aufsteigend nach valid_from sortiert
 */
function price_at(array $history, string $date): ?float
{
    $price = null;
    foreach ($history as $h) {
        if ($h['valid_from'] > $date) {
            break;
        }
        $price = (float) $h['amount'];
    }
    return $price ?? (isset($history[0]) ? (float) $history[0]['amount'] : null);
}

/**
 * Preisänderungen eines Vertrags (alle Einträge nach dem ersten, deren Betrag sich ändert).
 *
 * @param array<int,array> $history aufsteigend sortiert
 * @return list<array{id:mixed,date:string,from:float,to:float,delta:float,pct:?float,reason:?string,direction:string}>
 */
function history_changes(array $history): array
{
    $changes = [];
    for ($i = 1, $n = count($history); $i < $n; $i++) {
        $from = (float) $history[$i - 1]['amount'];
        $to   = (float) $history[$i]['amount'];
        if (abs($to - $from) < 0.005) {
            continue;
        }
        $changes[] = [
            'id'        => $history[$i]['id'] ?? null,
            'date'      => $history[$i]['valid_from'],
            'from'      => $from,
            'to'        => $to,
            'delta'     => round($to - $from, 2),
            'pct'       => $from > 0 ? round(($to - $from) / $from * 100, 1) : null,
            'reason'    => $history[$i]['reason'] ?? null,
            'direction' => $to > $from ? 'up' : 'down',
        ];
    }
    return $changes;
}

// ------------------------------------------------------------------
// Kosten je Monat / Jahr (periodisiert)
// ------------------------------------------------------------------

/**
 * Vertragskosten eines Kalendermonats (periodisiert, d. h. jeder angefangene Vertragsmonat zählt voll;
 * Jahres-/Quartalsbeiträge sind auf Monate verteilt). Preis = der am Monatsende gültige Preis.
 *
 * @param array<int,array> $contracts mit id, name, start_date, cancelled_at, status, billing_cycle, category_name, color_hex, icon
 * @param array<int,array> $historyByContract contract_id => aufsteigend sortierte Historie
 * @return array<int,array{id:int,name:string,category_id:?int,category_name:?string,color_hex:string,icon:string,amount:float}>
 *         amount ungerundet; Sortierung absteigend nach Betrag
 */
function month_cost_items(array $contracts, array $historyByContract, string $ym, string $todayStr): array
{
    $monthStart = $ym . '-01';
    $monthEnd   = (new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');

    $items = [];
    foreach ($contracts as $c) {
        if ($c['start_date'] > $monthEnd) {
            continue;
        }
        if (!empty($c['cancelled_at']) && $c['cancelled_at'] < $monthStart) {
            continue;
        }
        if (($c['status'] ?? 'active') === 'paused' && $monthEnd >= $todayStr) {
            continue; // pausiert: in laufendem und künftigen Monaten keine Kosten
        }
        $price = price_at($historyByContract[$c['id']] ?? [], $monthEnd);
        if ($price === null) {
            continue;
        }
        $items[] = [
            'id'            => (int) $c['id'],
            'name'          => $c['name'],
            'category_id'   => isset($c['category_id']) ? (int) $c['category_id'] : null,
            'category_name' => $c['category_name'] ?? null,
            'color_hex'     => $c['color_hex'] ?? '#8E8E93',
            'icon'          => $c['icon'] ?? '📄',
            'amount'        => to_monthly_amount($price, $c['billing_cycle']),
        ];
    }
    usort($items, fn($a, $b) => $b['amount'] <=> $a['amount']);
    return $items;
}

/** Summe der Monatskosten (Zahl). */
function month_cost_total(array $items): float
{
    return round(array_sum(array_map(fn($i) => round($i['amount'], 2), $items)), 2);
}

/**
 * Monatsreihe "YYYY-MM" => Gesamtkosten für den Zeitraum $fromYm..$toYm (inklusive).
 *
 * @return array<string,float>
 */
function monthly_cost_series(array $contracts, array $historyByContract, string $fromYm, string $toYm, string $todayStr): array
{
    $series = [];
    $cursor = new DateTimeImmutable($fromYm . '-01');
    $last   = new DateTimeImmutable($toYm . '-01');
    while ($cursor <= $last) {
        $ym = $cursor->format('Y-m');
        $series[$ym] = month_cost_total(month_cost_items($contracts, $historyByContract, $ym, $todayStr));
        $cursor = $cursor->modify('+1 month');
    }
    return $series;
}

/**
 * Kosten je Vertrag für ein Kalenderjahr. Im laufenden Jahr werden nur die bereits angefangenen
 * Monate gezählt (keine Hochrechnung), künftige Jahre sind leer.
 *
 * @return array{total:float, months:int, items:array<int,array>}
 */
function year_cost_breakdown(array $contracts, array $historyByContract, string $year, string $todayStr): array
{
    $currentYear = substr($todayStr, 0, 4);
    $lastMonth   = $year < $currentYear ? 12 : ($year === $currentYear ? (int) substr($todayStr, 5, 2) : 0);

    $perContract = [];
    for ($m = 1; $m <= $lastMonth; $m++) {
        $ym = sprintf('%s-%02d', $year, $m);
        foreach (month_cost_items($contracts, $historyByContract, $ym, $todayStr) as $item) {
            $id = $item['id'];
            if (!isset($perContract[$id])) {
                $perContract[$id] = $item;
                $perContract[$id]['amount'] = 0.0;
                $perContract[$id]['months'] = 0;
            }
            $perContract[$id]['amount'] += $item['amount'];
            $perContract[$id]['months']++;
        }
    }

    $items = array_values($perContract);
    foreach ($items as &$item) {
        $item['amount'] = round($item['amount'], 2);
    }
    unset($item);
    usort($items, fn($a, $b) => $b['amount'] <=> $a['amount']);

    return [
        'total'  => round(array_sum(array_column($items, 'amount')), 2),
        'months' => $lastMonth,
        'items'  => $items,
    ];
}

/**
 * Laufende monatliche Kosten an einem Stichtag ("Run-Rate"): alle an $date laufenden Verträge mit
 * dem an $date gültigen Preis. Aktuell pausierte Verträge zählen an keinem Stichtag mit – der Pausenstatus
 * ist nicht historisiert, und so erscheint eine länger bestehende Pause nicht als Rückgang im Vormonatsvergleich.
 * Basis für die Dashboard-Kachel und den Vergleich zum Vormonatsende.
 *
 * @return array<int,array> Items wie month_cost_items()
 */
function run_rate_items(array $contracts, array $historyByContract, string $date): array
{
    $items = [];
    foreach ($contracts as $c) {
        if (($c['status'] ?? 'active') === 'paused' || !contract_runs_on($c, $date)) {
            continue;
        }
        $price = price_at($historyByContract[$c['id']] ?? [], $date) ?? (float) ($c['current_cost'] ?? 0);
        $items[] = [
            'id'            => (int) $c['id'],
            'name'          => $c['name'],
            'category_id'   => isset($c['category_id']) ? (int) $c['category_id'] : null,
            'category_name' => $c['category_name'] ?? null,
            'color_hex'     => $c['color_hex'] ?? '#8E8E93',
            'icon'          => $c['icon'] ?? '📄',
            'amount'        => to_monthly_amount($price, $c['billing_cycle']),
        ];
    }
    usort($items, fn($a, $b) => $b['amount'] <=> $a['amount']);
    return $items;
}

// ------------------------------------------------------------------
// Zahlungskalender (Cashflow)
// ------------------------------------------------------------------

/**
 * Tatsächliche Abbuchungen im Zeitraum. Abbuchungstermine ergeben sich aus dem Abbuchungsanker
 * (billing_anchor_date, sonst Vertragsbeginn) im Rhythmus des Abrechnungszyklus. Betrag = zum
 * Abbuchungstag gültiger Preis je Zyklus.
 *
 * @return list<array{date:string,id:int,name:string,category_name:?string,color_hex:string,icon:string,cycle:string,amount:float}>
 */
function payments_in_range(array $contracts, array $historyByContract, DateTimeImmutable $from, DateTimeImmutable $to, string $todayStr): array
{
    $fromStr = $from->format('Y-m-d');
    $toStr   = $to->format('Y-m-d');
    $payments = [];

    foreach ($contracts as $c) {
        $interval = cycle_months($c['billing_cycle']);
        $anchor   = new DateTimeImmutable(!empty($c['billing_anchor_date']) ? $c['billing_anchor_date'] : $c['start_date']);
        if ($anchor > $to) {
            continue;
        }

        // Zum ersten relevanten Termin springen (einen Zyklus Puffer wegen Monatsende-Clamping)
        $monthsBetween = ((int) $from->format('Y') - (int) $anchor->format('Y')) * 12
            + ((int) $from->format('n') - (int) $anchor->format('n'));
        $k = max(0, intdiv($monthsBetween, $interval) - 1);

        for (; $k < 100000; $k++) {
            $date = add_months_clamped($anchor, $k * $interval)->format('Y-m-d');
            if ($date > $toStr) {
                break;
            }
            if ($date < $fromStr || $date < $c['start_date']) {
                continue;
            }
            if (!empty($c['cancelled_at']) && $date > $c['cancelled_at']) {
                break;
            }
            if (($c['status'] ?? 'active') === 'paused' && $date >= $todayStr) {
                continue;
            }
            $price = price_at($historyByContract[$c['id']] ?? [], $date) ?? (float) ($c['current_cost'] ?? 0);
            $payments[] = [
                'date'          => $date,
                'id'            => (int) $c['id'],
                'name'          => $c['name'],
                'category_name' => $c['category_name'] ?? null,
                'color_hex'     => $c['color_hex'] ?? '#8E8E93',
                'icon'          => $c['icon'] ?? '📄',
                'cycle'         => $c['billing_cycle'],
                'amount'        => $price,
            ];
        }
    }

    usort($payments, fn($a, $b) => [$a['date'], $a['name']] <=> [$b['date'], $b['name']]);
    return $payments;
}
