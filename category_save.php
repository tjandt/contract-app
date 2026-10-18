<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('categories.php');
}
csrf_verify();

$pdo = get_pdo();

$id    = !empty($_POST['id']) ? (int) $_POST['id'] : null;
$name  = trim((string) ($_POST['name'] ?? ''));
$icon  = trim((string) ($_POST['icon'] ?? '')) ?: '📄';
$color = strtoupper(trim((string) ($_POST['color_hex'] ?? '')));
$sort  = (int) ($_POST['sort_order'] ?? 0);

$error = null;
if ($name === '' || mb_strlen($name) > 60) {
    $error = 'Bitte einen Namen angeben (max. 60 Zeichen).';
} elseif (mb_strlen($icon) > 10) {
    $error = 'Das Icon darf höchstens 10 Zeichen lang sein.';
} elseif (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
    $error = 'Ungültige Farbe.';
} elseif ($sort < 0 || $sort > 9999) {
    $error = 'Die Reihenfolge muss zwischen 0 und 9999 liegen.';
} else {
    $dup = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE LOWER(name) = LOWER(?) AND id <> ?");
    $dup->execute([$name, $id ?? 0]);
    if ($dup->fetchColumn()) {
        $error = 'Eine Kategorie mit diesem Namen existiert bereits.';
    }
}
if ($error) {
    flash('error', $error);
    redirect('categories.php' . ($id ? '?edit=' . $id : ''));
}

if ($id) {
    $pdo->prepare("UPDATE categories SET name = ?, icon = ?, color_hex = ?, sort_order = ? WHERE id = ?")
        ->execute([$name, $icon, $color, $sort, $id]);
} else {
    $pdo->prepare("INSERT INTO categories (name, icon, color_hex, sort_order) VALUES (?, ?, ?, ?)")
        ->execute([$name, $icon, $color, $sort]);
}

flash('success', 'Kategorie gespeichert.');
redirect('categories.php');
