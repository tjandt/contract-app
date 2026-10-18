<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('categories.php');
}
csrf_verify();

// Verträge der Kategorie behalten ihre Daten, verlieren nur die Zuordnung (FK ON DELETE SET NULL)
get_pdo()->prepare("DELETE FROM categories WHERE id = ?")->execute([(int) ($_POST['id'] ?? 0)]);

flash('success', 'Kategorie gelöscht.');
redirect('categories.php');
