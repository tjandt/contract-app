<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = get_pdo();

// Zeitraum: 12 Monate ab ?from=YYYY-MM (Standard: laufender Monat)
$fromParam = $_GET['from'] ?? '';
$fromMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $fromParam)
    ? new DateTimeImmutable($fromParam . '-01')
    : new DateTimeImmutable('first day of this month');
$toDate = $fromMonth->modify('+11 months')->modify('last day of this month');

$calendar = get_payment_calendar($pdo, $fromMonth, $toDate);

$totals   = array_column($calendar, 'total');
$sum      = round(array_sum($totals), 2);
$average  = round($sum / 12, 2);
$maxTotal = max($totals) ?: 0.0;
$peakYm   = $maxTotal > 0 ? array_search($maxTotal, $totals, false) : null;
$peakKey  = $peakYm !== null ? array_keys($calendar)[$peakYm] : null;

// Zum Vergleich: periodisierte monatliche Kosten heute
$periodized = get_current_monthly_total($pdo);

$prevFrom = $fromMonth->modify('-12 months')->format('Y-m');
$nextFrom = $fromMonth->modify('+12 months')->format('Y-m');

$pageTitle = 'Zahlungen';
$activeNav = 'payments';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Zahlungskalender</h1>
        <p>Wann welche Beträge tatsächlich abgebucht werden – <?= e(format_month_long_de($fromMonth->format('Y-m'))) ?> bis <?= e(format_month_long_de($toDate->format('Y-m'))) ?></p>
    </div>
    <div class="pager">
        <a class="btn btn-secondary btn-sm" href="?from=<?= e($prevFrom) ?>">← 12 Monate zurück</a>
        <a class="btn btn-secondary btn-sm" href="payments.php">Heute</a>
        <a class="btn btn-secondary btn-sm" href="?from=<?= e($nextFrom) ?>">12 Monate vor →</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-tile">
        <div class="stat-label">Abbuchungen im Zeitraum</div>
        <div class="stat-value"><?= format_eur($sum) ?></div>
        <div class="stat-sub">⌀ <?= format_eur($average) ?> pro Monat</div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Teuerster Monat</div>
        <div class="stat-value"><?= format_eur($maxTotal) ?></div>
        <div class="stat-sub"><?= $peakKey ? e(format_month_long_de($peakKey)) : '–' ?></div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Periodisiert (heute)</div>
        <div class="stat-value"><?= format_eur($periodized) ?></div>
        <div class="stat-sub">Jahres-/Quartalsbeiträge auf Monate verteilt</div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Buchungen</div>
        <div class="stat-value"><?= array_sum(array_map(fn($m) => count($m['payments']), $calendar)) ?></div>
        <div class="stat-sub">im gewählten Zeitraum</div>
    </div>
</div>

<div class="card">
    <div class="section-title">Abbuchungen je Monat</div>
    <div class="pay-bars" role="img" aria-label="Balkendiagramm der Abbuchungen je Monat">
        <?php foreach ($calendar as $ym => $month):
            $height = $maxTotal > 0 ? max(2, round($month['total'] / $maxTotal * 100)) : 2;
        ?>
        <a class="pay-bar" href="#m-<?= e($ym) ?>" title="<?= e(format_month_long_de($ym)) ?>: <?= e(format_eur($month['total'])) ?>">
            <span class="pay-bar-value"><?= $month['total'] > 0 ? e(number_format($month['total'], 0, ',', '.')) : '' ?></span>
            <span class="pay-bar-fill <?= $month['total'] > $average * 1.5 ? 'high' : '' ?>" style="height: <?= $height ?>%"></span>
            <span class="pay-bar-label"><?= e(format_month_de($ym . '-01')) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <div class="chart-hint">Hervorgehoben sind Monate mit mehr als dem 1,5-fachen des Durchschnitts. Termine ergeben sich aus dem Abbuchungsanker des Vertrags (sonst dem Vertragsbeginn) und dem Abrechnungszyklus.</div>
</div>

<?php foreach ($calendar as $ym => $month): ?>
<div class="card mt-24" id="m-<?= e($ym) ?>">
    <div class="flex-between">
        <div class="section-title"><?= e(format_month_long_de($ym)) ?></div>
        <div class="section-title"><?= format_eur($month['total']) ?></div>
    </div>
    <?php if (empty($month['payments'])): ?>
        <div class="empty-state" style="padding:10px 0;">Keine Abbuchungen.</div>
    <?php endif; ?>
    <?php foreach ($month['payments'] as $p): ?>
    <a href="contract_view.php?id=<?= (int) $p['id'] ?>" class="month-breakdown-item pay-row">
        <span class="pay-date"><?= e(format_date_de($p['date'])) ?></span>
        <span class="cat-dot" style="background: <?= e($p['color_hex']) ?>"></span>
        <span class="mb-name"><?= e($p['name']) ?></span>
        <span class="mb-cat"><?= e(cycle_label($p['cycle'])) ?></span>
        <span class="mb-amount"><?= format_eur($p['amount']) ?></span>
    </a>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
