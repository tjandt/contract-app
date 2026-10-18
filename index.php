<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = get_pdo();

$mom             = get_month_over_month($pdo);
$currentMonthly  = $mom['current'];
$estimatedYearly = get_estimated_yearly_cost($pdo);
$activeCount     = count(get_run_rate_items($pdo));

$monthlySeries = get_monthly_spend_series($pdo, 24);
$yearlySeries  = get_yearly_series($pdo);
$categoryBreakdown = get_category_breakdown($pdo);

// Reminder-Liste: kündbare/bald kündbare Verträge
$contracts = get_contracts_with_deadlines($pdo);
$dueSoon   = array_filter($contracts, fn($c) => in_array($c['urgency'], ['urgent', 'warn'], true));
$reminders = array_slice(array_values($dueSoon), 0, 8);

$pendingConfirmations = get_pending_cancellation_confirmations($pdo);
$recentIncreases      = array_slice(get_price_overview($pdo)['recent_increases'], 0, 5);

// Beschriftungen für Chart.js
$monthLabels = array_map(fn($ym) => format_month_de($ym . '-01'), array_keys($monthlySeries));
$yearLabels = [];
foreach ($yearlySeries as $year => $y) {
    $yearLabels[] = $y['complete'] ? (string) $year : $year . ' (' . $y['months'] . ' Mon.)';
}

$chartData = [
    'monthly' => [
        'labels' => $monthLabels,
        'values' => array_values($monthlySeries),
        'keys'   => array_keys($monthlySeries), // "YYYY-MM", für den Monats-Drilldown
    ],
    'yearly' => [
        'labels' => $yearLabels,
        'values' => array_column($yearlySeries, 'total'),
        'keys'   => array_map('strval', array_keys($yearlySeries)), // für den Jahres-Drilldown
    ],
    'category' => [
        'labels' => array_map(fn($c) => $c['name'], $categoryBreakdown),
        'colors' => array_map(fn($c) => $c['color_hex'], $categoryBreakdown),
        'values' => array_map(fn($c) => round((float) $c['monthly_sum'], 2), $categoryBreakdown),
    ],
];

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Dashboard</h1>
        <p><?= $activeCount ?> aktive Verträge im Überblick</p>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-tile">
        <div class="stat-label">Monatliche Kosten</div>
        <div class="stat-value"><?= format_eur($currentMonthly) ?></div>
        <div class="stat-sub <?= $mom['diff'] > 0 ? 'up' : ($mom['diff'] < 0 ? 'down' : '') ?>">
            <?= $mom['diff'] > 0 ? '▲' : ($mom['diff'] < 0 ? '▼' : '＝') ?>
            <?= format_eur(abs($mom['diff'])) ?> ggü. Ende Vormonat<?= $mom['pct'] !== null ? ' (' . number_format($mom['pct'], 1, ',', '.') . '%)' : '' ?>
        </div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Geschätzte Jahreskosten</div>
        <div class="stat-value"><?= format_eur($estimatedYearly) ?></div>
        <div class="stat-sub">aktuelle Monatskosten × 12</div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Aktive Verträge</div>
        <div class="stat-value"><?= $activeCount ?></div>
        <div class="stat-sub"><a href="contracts.php" class="text-secondary">Alle anzeigen →</a></div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Bald kündbar</div>
        <div class="stat-value"><?= count($dueSoon) ?></div>
        <div class="stat-sub">innerhalb der nächsten <?= REMINDER_WARN_DAYS ?> Tage</div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="card chart-card">
        <div class="flex-between">
            <div class="section-title">Kostenverlauf</div>
        </div>
        <div class="chart-tabs" role="tablist">
            <button type="button" class="active" data-view="monthly">Monate</button>
            <button type="button" data-view="yearly">Jahre</button>
        </div>
        <div class="chart-canvas-wrap">
            <canvas id="spendChart"></canvas>
        </div>
        <div class="chart-hint" id="spendChartHint">Klicke auf einen Monat für die Aufstellung nach Vertrag.</div>
        <div class="chart-hint">Periodisiert: Jahres- und Quartalsbeiträge sind auf Monate verteilt, jeder angefangene Vertragsmonat zählt voll. Tatsächliche Abbuchungen siehe <a href="payments.php" class="text-secondary" style="text-decoration:underline;">Zahlungen</a>.</div>

        <div class="month-breakdown" id="monthBreakdown" hidden>
            <div class="flex-between">
                <div class="month-breakdown-title" id="monthBreakdownTitle">–</div>
                <button type="button" class="icon-btn" id="monthBreakdownClose" title="Schließen">✕</button>
            </div>
            <div class="month-breakdown-list" id="monthBreakdownList"></div>
            <div class="month-breakdown-total" id="monthBreakdownTotal"></div>
        </div>
    </div>

    <div class="card">
        <div class="section-title">Kosten nach Kategorie</div>
        <div class="chart-canvas-wrap chart-canvas-wrap-sm">
            <canvas id="categoryChart"></canvas>
        </div>
        <div class="legend">
            <?php if (empty($categoryBreakdown)): ?>
                <div class="empty-state">Noch keine Verträge erfasst.</div>
            <?php endif; ?>
            <?php foreach ($categoryBreakdown as $c): ?>
                <div class="legend-row">
                    <span class="cat-dot" style="background: <?= e($c['color_hex']) ?>"></span>
                    <span class="lbl"><?= e($c['icon']) ?> <?= e($c['name']) ?></span>
                    <span class="val"><?= format_eur((float) $c['monthly_sum']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="flex-between">
        <div class="section-title">Kündigungs-Reminder</div>
        <a href="contracts.php" class="text-secondary" style="font-size:13px;">Alle Verträge →</a>
    </div>
    <div class="reminder-list">
        <?php if (empty($reminders) && empty($pendingConfirmations)): ?>
            <div class="empty-state">Aktuell steht keine Kündigungsfrist an. 🎉</div>
        <?php endif; ?>
        <?php foreach ($reminders as $r):
            $d = $r['deadline_info'];
            $daysLeft = $d['days_left'];
            $badgeText = $daysLeft === 0 ? 'heute letzte Chance' : 'noch ' . $daysLeft . ' Tag' . ($daysLeft === 1 ? '' : 'e');
        ?>
        <a href="contract_view.php?id=<?= (int) $r['id'] ?>" class="reminder-item">
            <span class="cat-dot" style="background: <?= e($r['color_hex'] ?? '#8E8E93') ?>"></span>
            <span class="info">
                <span class="name"><?= e($r['name']) ?></span>
                <span class="sub">Kündbar bis <?= format_date_de($d['cancel_deadline']->format('Y-m-d')) ?> · Vertragsende <?= format_date_de($d['period_end']->format('Y-m-d')) ?></span>
            </span>
            <span class="badge badge-<?= e($r['urgency']) ?>"><?= $badgeText ?></span>
        </a>
        <?php endforeach; ?>
        <?php foreach ($pendingConfirmations as $p): ?>
        <a href="contract_view.php?id=<?= (int) $p['id'] ?>" class="reminder-item">
            <span class="cat-dot" style="background: <?= e($p['color_hex'] ?? '#8E8E93') ?>"></span>
            <span class="info">
                <span class="name"><?= e($p['name']) ?></span>
                <span class="sub">Kündigung am <?= format_date_de($p['cancellation_submitted_at']) ?> eingereicht – Bestätigung des Anbieters fehlt noch</span>
            </span>
            <span class="badge <?= $p['overdue'] ? 'badge-urgent' : 'badge-neutral' ?>">seit <?= (int) $p['days_waiting'] ?> Tag<?= $p['days_waiting'] === 1 ? '' : 'en' ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!empty($recentIncreases)): ?>
<div class="card mt-24">
    <div class="flex-between">
        <div class="section-title">Preiserhöhungen der letzten <?= PRICE_INCREASE_FLAG_DAYS ?> Tage</div>
        <a href="price_changes.php" class="text-secondary" style="font-size:13px;">Preisentwicklung →</a>
    </div>
    <div class="reminder-list">
        <?php foreach ($recentIncreases as $inc): ?>
        <a href="contract_view.php?id=<?= (int) $inc['contract_id'] ?>" class="reminder-item">
            <span class="info">
                <span class="name"><?= e($inc['contract_name']) ?></span>
                <span class="sub"><?= format_date_de($inc['date']) ?> · <?= format_eur($inc['from']) ?> → <?= format_eur($inc['to']) ?></span>
            </span>
            <span class="badge badge-warn"><?= e(format_pct($inc['pct'])) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <p class="form-hint" style="margin-top:12px;">Bei einseitigen Preiserhöhungen kann ein Sonderkündigungsrecht bestehen (z. B. bei Strom, Gas, Telekommunikation) – bitte Vertrag und Anbieterschreiben prüfen.</p>
</div>
<?php endif; ?>

<script id="chart-data" type="application/json"><?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset_url('assets/js/vendor/chart.umd.min.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/dashboard.js')) ?>"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
