<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contracts.php');
    exit;
}

$pdo = get_pdo();
$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare("DELETE FROM contracts WHERE id = ?");
$stmt->execute([$id]);

header('Location: contracts.php?deleted=1');
exit;
