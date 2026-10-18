<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = get_pdo();

$q          = trim($_GET['q'] ?? '');
$categoryId = $_GET['category'] ?? '';
$status     = $_GET['status'] ?? 'running';

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(c.name LIKE :q OR c.provider_name LIKE :q OR c.contract_number LIKE :q OR c.notes LIKE :q)";
    $params['q'] = '%' . $q . '%';
}
if ($categoryId !== '') {
    $where[] = "c.category_id = :cat";
    $params['cat'] = (int) $categoryId;
}
switch ($status) {
    case 'running': // laufend: aktiv oder bereits gekündigt, aber noch nicht beendet
        $where[] = "c.status != 'paused' AND (c.cancelled_at IS NULL OR c.cancelled_at >= CURDATE())";
        break;
    case 'paused':
        $where[] = "c.status = 'paused'";
        break;
    case 'ended': // beendet: Kündigungsdatum liegt in der Vergangenheit
        $where[] = "c.cancelled_at IS NOT NULL AND c.cancelled_at < CURDATE()";
        break;
    case 'all':
    default:
        break;
}

$sql = "SELECT c.*, cat.name AS category_name, cat.color_hex, cat.icon
        FROM contracts c
        LEFT JOIN categories cat ON cat.id = c.category_id";
if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY (c.status != 'paused' AND (c.cancelled_at IS NULL OR c.cancelled_at >= CURDATE())) DESC, c.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$contracts = $stmt->fetchAll();

foreach ($contracts as &$c) {
    $c['deadline_info'] = calculate_contract_deadline($c);
    $c['urgency'] = deadline_urgency($c['deadline_info']['days_left']);
    $c['monthly_equivalent'] = to_monthly_amount((float) $c['current_cost'], $c['billing_cycle']);
}
unset($c);

// ---------------- Sortierung (klickbare Tabellenspalten) ----------------
$sortableColumns = ['name', 'category', 'cost', 'deadline'];
$defaultDir = ['name' => 'asc', 'category' => 'asc', 'cost' => 'desc', 'deadline' => 'asc'];

$sort = in_array($_GET['sort'] ?? '', $sortableColumns, true) ? $_GET['sort'] : '';
$dir  = in_array($_GET['dir'] ?? '', ['asc', 'desc'], true) ? $_GET['dir'] : ($sort ? $defaultDir[$sort] : '');

if ($sort !== '') {
    usort($contracts, function ($a, $b) use ($sort, $dir) {
        $cmp = match ($sort) {
            'name'     => strcasecmp($a['name'], $b['name']),
            'category' => strcasecmp($a['category_name'] ?? '', $b['category_name'] ?? ''),
            'cost'     => $a['monthly_equivalent'] <=> $b['monthly_equivalent'],
            'deadline' => ($a['deadline_info']['days_left'] ?? PHP_INT_MAX) <=> ($b['deadline_info']['days_left'] ?? PHP_INT_MAX),
            default    => 0,
        };
        return $dir === 'desc' ? -$cmp : $cmp;
    });
}

/** Erzeugt die URL für einen klickbaren Spaltenkopf (toggelt die Richtung, behält Filter bei). */
function sort_column_url(string $key, string $currentSort, string $currentDir, array $defaultDir, array $filterParams): string
{
    $newDir = ($currentSort === $key && $currentDir === $defaultDir[$key])
        ? ($defaultDir[$key] === 'asc' ? 'desc' : 'asc')
        : $defaultDir[$key];
    return '?' . http_build_query($filterParams + ['sort' => $key, 'dir' => $newDir]);
}

function sort_indicator(string $key, string $currentSort, string $currentDir): string
{
    if ($currentSort !== $key) return '';
    return $currentDir === 'asc' ? ' ▲' : ' ▼';
}

$filterParams = ['q' => $q, 'category' => $categoryId, 'status' => $status];

$categories = $pdo->query("SELECT * FROM categories ORDER BY sort_order")->fetchAll();

$pageTitle = 'Verträge';
$activeNav = 'contracts';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Verträge</h1>
        <p><?= count($contracts) ?> Ergebnis<?= count($contracts) === 1 ? '' : 'se' ?></p>
    </div>
    <a href="contract_edit.php" class="btn btn-primary">+ Neuer Vertrag</a>
</div>

<form method="get" class="toolbar">
    <?php if ($sort !== ''): ?>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <input type="hidden" name="dir" value="<?= e($dir) ?>">
    <?php endif; ?>
    <div class="search-box">
        <span class="icon">⌕</span>
        <input type="search" name="q" placeholder="Suche nach Name, Anbieter, Vertragsnummer…" value="<?= e($q) ?>">
    </div>
    <select name="category" onchange="this.form.submit()">
        <option value="">Alle Kategorien</option>
        <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>" <?= (string) $categoryId === (string) $cat['id'] ? 'selected' : '' ?>>
                <?= e($cat['icon']) ?> <?= e($cat['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <select name="status" onchange="this.form.submit()">
        <option value="running" <?= $status === 'running' ? 'selected' : '' ?>>Laufend</option>
        <option value="paused" <?= $status === 'paused' ? 'selected' : '' ?>>Pausiert</option>
        <option value="ended" <?= $status === 'ended' ? 'selected' : '' ?>>Beendet</option>
        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Alle</option>
    </select>
    <button type="submit" class="btn btn-secondary btn-sm">Filtern</button>
</form>

<div class="contract-table-wrap">
<table>
    <thead>
        <tr>
            <th><a href="<?= e(sort_column_url('name', $sort, $dir, $defaultDir, $filterParams)) ?>" class="th-sort">Vertrag<?= sort_indicator('name', $sort, $dir) ?></a></th>
            <th><a href="<?= e(sort_column_url('category', $sort, $dir, $defaultDir, $filterParams)) ?>" class="th-sort">Kategorie<?= sort_indicator('category', $sort, $dir) ?></a></th>
            <th><a href="<?= e(sort_column_url('cost', $sort, $dir, $defaultDir, $filterParams)) ?>" class="th-sort">Kosten<?= sort_indicator('cost', $sort, $dir) ?></a></th>
            <th>Status</th>
            <th><a href="<?= e(sort_column_url('deadline', $sort, $dir, $defaultDir, $filterParams)) ?>" class="th-sort">Kündbar<?= sort_indicator('deadline', $sort, $dir) ?></a></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($contracts)): ?>
        <tr><td colspan="6"><div class="empty-state">Keine Verträge gefunden.</div></td></tr>
        <?php endif; ?>
        <?php foreach ($contracts as $c):
            $d = $c['deadline_info'];
        ?>
        <tr onclick="window.location='contract_view.php?id=<?= (int)$c['id'] ?>'" style="cursor:pointer;">
            <td data-label="Vertrag" class="name-cell">
                <div class="contract-name"><?= e($c['name']) ?></div>
                <?php if ($c['provider_name']): ?><div class="provider"><?= e($c['provider_name']) ?></div><?php endif; ?>
            </td>
            <td data-label="Kategorie">
                <?php if ($c['category_name']): ?>
                <span class="cat-pill">
                    <span class="cat-dot" style="background: <?= e($c['color_hex']) ?>"></span>
                    <?= e($c['category_name']) ?>
                </span>
                <?php else: ?>
                    <span class="text-secondary">–</span>
                <?php endif; ?>
            </td>
            <td data-label="Kosten" class="num">
                <?= format_eur((float) $c['current_cost']) ?>
                <span class="text-secondary" style="font-size:12px;"> / <?= cycle_label($c['billing_cycle']) ?></span>
            </td>
            <td data-label="Status">
                <span class="badge <?= effective_status_badge_class($c) ?>"><?= effective_status_label($c) ?></span>
            </td>
            <td data-label="Kündbar">
                <?php if (!empty($c['cancelled_at'])): ?>
                    <span class="text-secondary">–</span>
                <?php elseif ($d['days_left'] !== null):
                    $daysLeft = $d['days_left'];
                    $badgeClass = 'badge-' . $c['urgency'];
                    if ($c['urgency'] === 'expired') { $txt = 'Frist verpasst'; }
                    else { $txt = format_date_de($d['cancel_deadline']->format('Y-m-d')); }
                ?>
                    <span class="badge <?= $badgeClass ?>"><?= $txt ?></span>
                <?php else: ?>
                    <span class="text-secondary">–</span>
                <?php endif; ?>
            </td>
            <td data-label="" class="actions" onclick="event.stopPropagation();">
                <a href="contract_edit.php?id=<?= (int) $c['id'] ?>" class="btn btn-secondary btn-sm">Bearbeiten</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
