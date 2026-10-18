<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = get_pdo();

$categories = $pdo->query("SELECT cat.*, COUNT(c.id) AS contract_count
                             FROM categories cat
                             LEFT JOIN contracts c ON c.category_id = cat.id
                            GROUP BY cat.id
                            ORDER BY cat.sort_order, cat.name")->fetchAll();

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = null;
foreach ($categories as $cat) {
    if ($editId !== null && (int) $cat['id'] === $editId) {
        $editing = $cat;
    }
}
$form = $editing ?? ['id' => '', 'name' => '', 'color_hex' => '#0A84FF', 'icon' => '📄', 'sort_order' => (count($categories) + 1)];

$pageTitle = 'Kategorien';
$activeNav = 'categories';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Kategorien</h1>
        <p>Name, Farbe und Icon der Vertragskategorien verwalten</p>
    </div>
</div>

<div class="detail-grid">
    <div class="card">
        <div class="section-title">Alle Kategorien</div>
        <?php if (empty($categories)): ?>
            <div class="empty-state">Noch keine Kategorien.</div>
        <?php endif; ?>
        <?php foreach ($categories as $cat): ?>
        <div class="history-item">
            <div class="cat-row">
                <span class="cat-dot" style="background: <?= e($cat['color_hex']) ?>"></span>
                <span><?= e($cat['icon']) ?> <?= e($cat['name']) ?></span>
                <span class="text-secondary" style="font-size:12.5px;"><?= (int) $cat['contract_count'] ?> Vertrag<?= (int) $cat['contract_count'] === 1 ? '' : 'e' ?></span>
            </div>
            <div class="history-item-right">
                <a href="categories.php?edit=<?= (int) $cat['id'] ?>" class="btn btn-secondary btn-sm">Bearbeiten</a>
                <form method="post" action="category_delete.php" class="inline-form"
                      onsubmit="return confirm('Kategorie „<?= e(addslashes($cat['name'])) ?>“ löschen? <?= (int) $cat['contract_count'] ?> Vertrag/Verträge verlieren ihre Kategorie.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $cat['id'] ?>">
                    <button type="submit" class="history-delete-btn" title="Kategorie löschen" aria-label="Kategorie <?= e($cat['name']) ?> löschen">✕</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="section-title"><?= $editing ? 'Kategorie bearbeiten' : 'Neue Kategorie' ?></div>
        <form method="post" action="category_save.php">
            <?= csrf_field() ?>
            <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
            <div class="form-field" style="margin-bottom:14px;">
                <label for="cat_name">Name *</label>
                <input type="text" id="cat_name" name="name" required maxlength="60" value="<?= e($form['name']) ?>">
            </div>
            <div class="form-field" style="margin-bottom:14px;">
                <label for="cat_icon">Icon (Emoji)</label>
                <input type="text" id="cat_icon" name="icon" maxlength="10" value="<?= e($form['icon']) ?>">
            </div>
            <div class="form-field" style="margin-bottom:14px;">
                <label for="cat_color">Farbe</label>
                <input type="color" id="cat_color" name="color_hex" value="<?= e($form['color_hex']) ?>">
            </div>
            <div class="form-field" style="margin-bottom:16px;">
                <label for="cat_sort">Reihenfolge</label>
                <input type="number" id="cat_sort" name="sort_order" min="0" max="9999" value="<?= (int) $form['sort_order'] ?>">
                <div class="form-hint">Kleinere Zahlen stehen weiter oben in Auswahllisten.</div>
            </div>
            <div class="form-actions" style="justify-content:flex-start;">
                <button type="submit" class="btn btn-primary"><?= $editing ? 'Speichern' : 'Kategorie anlegen' ?></button>
                <?php if ($editing): ?><a href="categories.php" class="btn btn-secondary">Abbrechen</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
