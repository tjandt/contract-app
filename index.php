<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = get_pdo();

$currentMonthly = get_current_monthly_total($pdo);
$estimatedYearly = get_estimated_yearly_cost($pdo);
$activeCount = (int) $pdo->query("SELECT COUNT(*) FROM contracts
                                   WHERE status != 'paused'
                                     AND (cancelled_at IS NULL OR cancelled_at >= CURDATE())")->fetchColumn();

$monthlySeries = get_monthly_spend_series($pdo, 24);
$yearlySeries  = aggregate_yearly($monthlySeries);

$monthKeys = array_keys($monthlySeries);
$lastKey = end($monthKeys);
$prevKey = $monthKeys[count($monthKeys) - 2] ?? null;
$lastMonthVal = $monthlySeries[$lastKey] ?? 0;
$prevMonthVal = $prevKey ? $monthlySeries[$prevKey] : null;
$momDiff = $prevMonthVal !== null ? $lastMonthVal - $prevMonthVal : null;
$momPct  = ($prevMonthVal !== null && $prevMonthVal > 0) ? ($momDiff / $prevMonthVal) * 100 : null;

// Kategorie-Aufschlüsselung der aktuellen monatlichen Kosten
$catStmt = $pdo->query("SELECT cat.id, cat.name, cat.color_hex, cat.icon,
                                SUM(CASE c.billing_cycle
                                    WHEN 'yearly' THEN c.current_cost/12
                                    WHEN 'quarterly' THEN c.current_cost/3
                                    ELSE c.current_cost END) AS monthly_sum
                         FROM contracts c
                         JOIN categories cat ON cat.id = c.category_id
                         WHERE c.status != 'paused'
                           AND (c.cancelled_at IS NULL OR c.cancelled_at >= CURDATE())
                         GROUP BY cat.id, cat.name, cat.color_hex, cat.icon
                         ORDER BY monthly_sum DESC");
$categoryBreakdown = $catStmt->fetchAll();

// Reminder-Liste: kündbare/bald kündbare Verträge
$contracts = get_contracts_with_deadlines($pdo);
$reminders = array_filter($contracts, fn($c) => in_array($c['urgency'], ['urgent', 'warn', 'expired'], true));
$reminders = array_slice($reminders, 0, 8);

// Formatierte Label-Arrays für Chart.js
$monthLabels = [];
foreach (array_keys($monthlySeries) as $ym) {
    $monthLabels[] = format_month_de($ym . '-01');
}

$chartData = [
    'monthly' => [
        'labels' => $monthLabels,
        'values' => array_values($monthlySeries),
        'keys'   => array_keys($monthlySeries), // "YYYY-MM", für den Monats-Drilldown
    ],
    'yearly' => [
        'labels' => array_keys($yearlySeries),
        'values' => array_values($yearlySeries),
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
        <?php if ($momDiff !== null): ?>
            <div class="stat-sub <?= $momDiff > 0 ? 'up' : ($momDiff < 0 ? 'down' : '') ?>">
                <?= $momDiff > 0 ? '▲' : ($momDiff < 0 ? '▼' : '＝') ?>
                <?= format_eur(abs($momDiff)) ?> ggü. Vormonat<?= $momPct !== null ? ' (' . number_format($momPct, 1, ',', '.') . '%)' : '' ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Geschätzte Jahreskosten</div>
        <div class="stat-value"><?= format_eur($estimatedYearly) ?></div>
        <div class="stat-sub">Hochrechnung auf Basis aktueller Verträge</div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Aktive Verträge</div>
        <div class="stat-value"><?= $activeCount ?></div>
        <div class="stat-sub"><a href="contracts.php" class="text-secondary">Alle anzeigen →</a></div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Bald kündbar</div>
        <div class="stat-value"><?= count(array_filter($contracts, fn($c) => in_array($c['urgency'], ['urgent','warn'], true))) ?></div>
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
        <?php if (empty($reminders)): ?>
            <div class="empty-state">Aktuell steht keine Kündigungsfrist an. 🎉</div>
        <?php endif; ?>
        <?php foreach ($reminders as $r):
            $d = $r['deadline_info'];
            $badgeClass = 'badge-' . $r['urgency'];
            $daysLeft = $d['days_left'];
            if ($r['urgency'] === 'expired') {
                $badgeText = 'Frist verpasst';
            } elseif ($daysLeft === 0) {
                $badgeText = 'heute letzte Chance';
            } else {
                $badgeText = 'noch ' . $daysLeft . ' Tag' . ($daysLeft === 1 ? '' : 'e');
            }
        ?>
        <a href="contract_view.php?id=<?= (int) $r['id'] ?>" class="reminder-item">
            <span class="cat-dot" style="background: <?= e($r['color_hex'] ?? '#8E8E93') ?>"></span>
            <span class="info">
                <span class="name"><?= e($r['name']) ?></span>
                <span class="sub">Kündbar bis <?= format_date_de($d['cancel_deadline']?->format('Y-m-d')) ?></span>
            </span>
            <span class="badge <?= $badgeClass ?>"><?= $badgeText ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<script id="chart-data" type="application/json"><?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset_url('assets/js/vendor/chart.umd.min.js')) ?>"></script>
<script src="<?= e(asset_url('assets/js/dashboard.js')) ?>"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
