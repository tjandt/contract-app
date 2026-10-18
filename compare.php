<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = get_pdo();

$monthA = $_GET['a'] ?? '';
$monthB = $_GET['b'] ?? '';
$validA = (bool) preg_match('/^\d{4}-\d{2}$/', $monthA);
$validB = (bool) preg_match('/^\d{4}-\d{2}$/', $monthB);

// Sinnvolle Vorbelegung beim ersten Aufruf: aktueller Monat vs. vor 12 Monaten
if (!$validA && !$validB) {
    $monthB = date('Y-m');
    $monthA = (new DateTimeImmutable('first day of this month'))->modify('-12 months')->format('Y-m');
    $validA = $validB = true;
}

$result = null;

if ($validA && $validB && $monthA !== $monthB) {
    $bdA = get_month_breakdown($pdo, $monthA);
    $bdB = get_month_breakdown($pdo, $monthB);

    $itemsA = [];
    foreach ($bdA['items'] as $i) $itemsA[$i['id']] = $i;
    $itemsB = [];
    foreach ($bdB['items'] as $i) $itemsB[$i['id']] = $i;

    $allIds = array_unique(array_merge(array_keys($itemsA), array_keys($itemsB)));

    $added = $removed = $increased = $decreased = $unchanged = [];

    foreach ($allIds as $id) {
        $a = $itemsA[$id] ?? null;
        $b = $itemsB[$id] ?? null;

        if ($a && $b) {
            $delta = round($b['amount'] - $a['amount'], 2);
            if (abs($delta) < 0.01) {
                $unchanged[] = ['item' => $b, 'amount_a' => $a['amount'], 'amount_b' => $b['amount'], 'delta' => $delta];
            } elseif ($delta > 0) {
                $increased[] = ['item' => $b, 'amount_a' => $a['amount'], 'amount_b' => $b['amount'], 'delta' => $delta];
            } else {
                $decreased[] = ['item' => $b, 'amount_a' => $a['amount'], 'amount_b' => $b['amount'], 'delta' => $delta];
            }
        } elseif ($a && !$b) {
            $removed[] = $a;
        } elseif (!$a && $b) {
            $added[] = $b;
        }
    }

    usort($added, fn($x, $y) => $y['amount'] <=> $x['amount']);
    usort($removed, fn($x, $y) => $y['amount'] <=> $x['amount']);
    usort($increased, fn($x, $y) => $y['delta'] <=> $x['delta']);
    usort($decreased, fn($x, $y) => $x['delta'] <=> $y['delta']);
    usort($unchanged, fn($x, $y) => strcasecmp($x['item']['name'], $y['item']['name']));

    // ---- Kategorie-Vergleich ----
    $catA = $catB = $catMeta = [];
    foreach ($bdA['items'] as $i) {
        $key = $i['category_name'] ?? 'Ohne Kategorie';
        $catA[$key] = ($catA[$key] ?? 0) + $i['amount'];
        $catMeta[$key] = ['color_hex' => $i['color_hex'], 'icon' => $i['icon']];
    }
    foreach ($bdB['items'] as $i) {
        $key = $i['category_name'] ?? 'Ohne Kategorie';
        $catB[$key] = ($catB[$key] ?? 0) + $i['amount'];
        $catMeta[$key] ??= ['color_hex' => $i['color_hex'], 'icon' => $i['icon']];
    }
    $allCats = array_unique(array_merge(array_keys($catA), array_keys($catB)));
    $categoryDiff = [];
    foreach ($allCats as $cat) {
        $a = round($catA[$cat] ?? 0, 2);
        $b = round($catB[$cat] ?? 0, 2);
        $categoryDiff[] = [
            'name' => $cat, 'meta' => $catMeta[$cat], 'a' => $a, 'b' => $b, 'delta' => round($b - $a, 2),
        ];
    }
    usort($categoryDiff, fn($x, $y) => abs($y['delta']) <=> abs($x['delta']));

    $result = [
        'total_a' => $bdA['total'], 'total_b' => $bdB['total'],
        'added' => $added, 'removed' => $removed,
        'increased' => $increased, 'decreased' => $decreased, 'unchanged' => $unchanged,
        'categories' => $categoryDiff,
    ];
}

$pageTitle = 'Vergleich';
$activeNav = 'compare';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Monatsvergleich</h1>
        <p>Zwei Monate gegenüberstellen: was ist gleich geblieben, neu, weggefallen oder teurer geworden?</p>
    </div>
</div>

<form method="get" class="card compare-form">
    <div class="form-grid">
        <div class="form-field">
            <label for="a">Monat A</label>
            <input type="month" id="a" name="a" value="<?= e($monthA) ?>" required>
        </div>
        <div class="form-field">
            <label for="b">Monat B</label>
            <input type="month" id="b" name="b" value="<?= e($monthB) ?>" required>
        </div>
    </div>
    <div class="form-actions" style="justify-content:flex-start; margin-top:18px;">
        <button type="submit" class="btn btn-primary">Vergleichen</button>
    </div>
</form>

<?php if ($validA && $validB && $monthA === $monthB): ?>
    <div class="alert alert-error mt-24">Bitte zwei unterschiedliche Monate wählen.</div>
<?php endif; ?>

<?php if ($result): ?>

    <div class="compare-summary mt-24">
        <div class="stat-tile">
            <div class="stat-label"><?= e(format_month_long_de($monthA)) ?></div>
            <div class="stat-value"><?= format_eur($result['total_a']) ?></div>
        </div>
        <div class="compare-arrow">→</div>
        <div class="stat-tile">
            <div class="stat-label"><?= e(format_month_long_de($monthB)) ?></div>
            <div class="stat-value"><?= format_eur($result['total_b']) ?></div>
        </div>
        <div class="stat-tile">
            <?php
                $totalDelta = round($result['total_b'] - $result['total_a'], 2);
                $totalPct = $result['total_a'] > 0 ? ($totalDelta / $result['total_a']) * 100 : null;
            ?>
            <div class="stat-label">Differenz</div>
            <div class="stat-value <?= $totalDelta > 0 ? 'text-up' : ($totalDelta < 0 ? 'text-down' : '') ?>">
                <?= $totalDelta > 0 ? '+' : '' ?><?= format_eur($totalDelta) ?>
            </div>
            <?php if ($totalPct !== null): ?>
                <div class="stat-sub"><?= $totalDelta >= 0 ? '+' : '' ?><?= number_format($totalPct, 1, ',', '.') ?>%</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mt-24">
        <div class="section-title">Kosten nach Kategorie im Vergleich</div>
        <?php if (empty($result['categories'])): ?>
            <div class="empty-state">Keine Daten in diesem Zeitraum.</div>
        <?php else: ?>
        <div class="compare-cat-list">
            <?php foreach ($result['categories'] as $c): ?>
            <div class="compare-cat-row">
                <span class="cat-dot" style="background:<?= e($c['meta']['color_hex'] ?? '#8E8E93') ?>"></span>
                <span class="cc-name"><?= e($c['meta']['icon'] ?? '') ?> <?= e($c['name']) ?></span>
                <span class="cc-amt"><?= format_eur($c['a']) ?></span>
                <span class="cc-sep">→</span>
                <span class="cc-amt"><?= format_eur($c['b']) ?></span>
                <span class="badge <?= $c['delta'] > 0 ? 'badge-warn' : ($c['delta'] < 0 ? 'badge-ok' : 'badge-neutral') ?>">
                    <?= $c['delta'] > 0 ? '+' : '' ?><?= format_eur($c['delta']) ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="compare-grid mt-24">
        <div class="card">
            <div class="section-title">🆕 Neu hinzugekommen (<?= count($result['added']) ?>)</div>
            <?php if (empty($result['added'])): ?>
                <div class="empty-state">Nichts Neues.</div>
            <?php else: foreach ($result['added'] as $i): ?>
                <div class="compare-item">
                    <span class="cat-dot" style="background:<?= e($i['color_hex']) ?>"></span>
                    <span class="ci-name"><?= e($i['name']) ?></span>
                    <span class="ci-cat"><?= e($i['category_name'] ?? '') ?></span>
                    <span class="ci-amt text-up">+<?= format_eur($i['amount']) ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="card">
            <div class="section-title">🗑️ Weggefallen (<?= count($result['removed']) ?>)</div>
            <?php if (empty($result['removed'])): ?>
                <div class="empty-state">Nichts weggefallen.</div>
            <?php else: foreach ($result['removed'] as $i): ?>
                <div class="compare-item">
                    <span class="cat-dot" style="background:<?= e($i['color_hex']) ?>"></span>
                    <span class="ci-name"><?= e($i['name']) ?></span>
                    <span class="ci-cat"><?= e($i['category_name'] ?? '') ?></span>
                    <span class="ci-amt text-down">−<?= format_eur($i['amount']) ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="card mt-24">
        <div class="section-title">💸 Preisänderungen (<?= count($result['increased']) + count($result['decreased']) ?>)</div>
        <?php if (empty($result['increased']) && empty($result['decreased'])): ?>
            <div class="empty-state">Keine Preisänderungen zwischen diesen Monaten.</div>
        <?php else: ?>
            <?php foreach (array_merge($result['increased'], $result['decreased']) as $row): ?>
                <div class="compare-item">
                    <span class="cat-dot" style="background:<?= e($row['item']['color_hex']) ?>"></span>
                    <span class="ci-name"><?= e($row['item']['name']) ?></span>
                    <span class="ci-cat"><?= e($row['item']['category_name'] ?? '') ?></span>
                    <span class="ci-change"><?= format_eur($row['amount_a']) ?> → <?= format_eur($row['amount_b']) ?></span>
                    <span class="badge <?= $row['delta'] > 0 ? 'badge-warn' : 'badge-ok' ?>">
                        <?= $row['delta'] > 0 ? '+' : '' ?><?= format_eur($row['delta']) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card mt-24">
        <div class="section-title">✓ Unverändert (<?= count($result['unchanged']) ?>)</div>
        <?php if (empty($result['unchanged'])): ?>
            <div class="empty-state">Keine unveränderten Verträge.</div>
        <?php else: ?>
            <div class="compare-unchanged-list">
            <?php foreach ($result['unchanged'] as $row): ?>
                <div class="compare-item compare-item-muted">
                    <span class="cat-dot" style="background:<?= e($row['item']['color_hex']) ?>"></span>
                    <span class="ci-name"><?= e($row['item']['name']) ?></span>
                    <span class="ci-cat"><?= e($row['item']['category_name'] ?? '') ?></span>
                    <span class="ci-amt text-secondary"><?= format_eur($row['amount_b']) ?></span>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
