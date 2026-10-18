<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = get_pdo();
$overview = get_price_overview($pdo);
$lfl = $overview['like_for_like'];

$withChanges = array_filter($overview['contracts'], fn($c) => !empty($c['changes']));
$increaseCount = count(array_filter($overview['changes'], fn($c) => $c['direction'] === 'up'));
$decreaseCount = count($overview['changes']) - $increaseCount;

$pageTitle = 'Preisentwicklung';
$activeNav = 'prices';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Preisentwicklung</h1>
        <p>Wie stark sind deine Verträge im Lauf der Zeit teurer (oder günstiger) geworden?</p>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-tile">
        <div class="stat-label">Veränderung der Fixkosten (<?= (int) $lfl['months'] ?> Monate)</div>
        <div class="stat-value <?= $lfl['diff'] > 0 ? 'text-up' : ($lfl['diff'] < 0 ? 'text-down' : '') ?>"><?= e(format_pct($lfl['pct'])) ?></div>
        <div class="stat-sub"><?= ($lfl['diff'] > 0 ? '+' : '') . format_eur($lfl['diff']) ?> pro Monat bei <?= (int) $lfl['contracts'] ?> unveränderten Verträgen</div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Preiserhöhungen</div>
        <div class="stat-value"><?= $increaseCount ?></div>
        <div class="stat-sub">insgesamt erfasst</div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Preissenkungen</div>
        <div class="stat-value"><?= $decreaseCount ?></div>
        <div class="stat-sub">insgesamt erfasst</div>
    </div>
    <div class="stat-tile">
        <div class="stat-label">Aktuell zu prüfen</div>
        <div class="stat-value"><?= count($overview['recent_increases']) ?></div>
        <div class="stat-sub">Erhöhungen der letzten <?= PRICE_INCREASE_FLAG_DAYS ?> Tage</div>
    </div>
</div>
<p class="form-hint" style="margin:-8px 0 20px;">
    Verglichen werden nur Verträge, die vor <?= (int) $lfl['months'] ?> Monaten (<?= format_date_de($lfl['from']) ?>) und heute liefen – neue oder beendete Verträge verfälschen die Zahl so nicht.
</p>

<?php if (!empty($overview['recent_increases'])): ?>
<div class="card alert-card">
    <div class="section-title">Mögliches Sonderkündigungsrecht prüfen</div>
    <p class="text-secondary" style="margin:0 0 12px; font-size:13.5px;">
        Bei einseitigen Preiserhöhungen besteht je nach Vertragsart (z. B. Strom, Gas, Telekommunikation) oft ein Sonderkündigungsrecht
        mit eigener Frist. Prüfe Vertrag und Anbieterschreiben bzw. frage die Verbraucherzentrale – die App kann das nicht verbindlich beurteilen.
    </p>
    <?php foreach ($overview['recent_increases'] as $inc): ?>
    <a href="contract_view.php?id=<?= (int) $inc['contract_id'] ?>" class="month-breakdown-item pay-row">
        <span class="pay-date"><?= e(format_date_de($inc['date'])) ?></span>
        <span class="mb-name"><?= e($inc['contract_name']) ?></span>
        <span class="mb-cat"><?= e(format_eur($inc['from'])) ?> → <?= e(format_eur($inc['to'])) ?></span>
        <span class="mb-amount text-up"><?= e(format_pct($inc['pct'])) ?></span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card mt-24">
    <div class="section-title">Preisentwicklung je Vertrag</div>
    <?php if (empty($overview['contracts'])): ?>
        <div class="empty-state">Noch keine Verträge erfasst.</div>
    <?php else: ?>
    <div class="contract-table-wrap">
    <table>
        <thead>
            <tr>
                <th>Vertrag</th>
                <th class="num">Startpreis</th>
                <th class="num">Aktuell</th>
                <th class="num">Veränderung</th>
                <th class="num">Ø pro Jahr</th>
                <th class="num">Erhöhungen</th>
                <th>Letzte Änderung</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($overview['contracts'] as $c): ?>
            <tr onclick="window.location='contract_view.php?id=<?= (int) $c['id'] ?>'" style="cursor:pointer;">
                <td data-label="Vertrag" class="name-cell">
                    <a class="contract-name" href="contract_view.php?id=<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a>
                    <?php if ($c['ended']): ?><div class="provider">beendet</div><?php endif; ?>
                </td>
                <td data-label="Startpreis" class="num"><?= format_eur($c['first']) ?></td>
                <td data-label="Aktuell" class="num"><?= format_eur($c['current']) ?></td>
                <td data-label="Veränderung" class="num <?= ($c['pct'] ?? 0) > 0 ? 'text-up' : (($c['pct'] ?? 0) < 0 ? 'text-down' : '') ?>"><?= e(format_pct($c['pct'])) ?></td>
                <td data-label="Ø pro Jahr" class="num"><?= $c['pct_per_year'] !== null ? e(format_pct($c['pct_per_year'])) : '–' ?></td>
                <td data-label="Erhöhungen" class="num"><?= (int) $c['increases'] ?></td>
                <td data-label="Letzte Änderung"><?= $c['last_change'] ? e(format_date_de($c['last_change'])) : '–' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<div class="card mt-24">
    <div class="section-title">Alle Preisänderungen</div>
    <?php if (empty($overview['changes'])): ?>
        <div class="empty-state">Noch keine Preisänderungen erfasst. Änderungen trägst du in der Vertragsdetailansicht ein.</div>
    <?php endif; ?>
    <?php foreach ($overview['changes'] as $ch): ?>
    <a href="contract_view.php?id=<?= (int) $ch['contract_id'] ?>" class="month-breakdown-item pay-row">
        <span class="pay-date"><?= e(format_date_de($ch['date'])) ?></span>
        <span class="mb-name"><?= e($ch['contract_name']) ?><?php if ($ch['reason']): ?> <span class="text-secondary">· <?= e($ch['reason']) ?></span><?php endif; ?></span>
        <span class="mb-cat"><?= e(format_eur($ch['from'])) ?> → <?= e(format_eur($ch['to'])) ?></span>
        <span class="mb-amount <?= $ch['direction'] === 'up' ? 'text-up' : 'text-down' ?>"><?= e(format_pct($ch['pct'])) ?></span>
    </a>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
