<?php
/** Erwartet optional: $pageTitle, $activeNav */
$activeNav = $activeNav ?? '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . ' – ' : '' ?><?= htmlspecialchars(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
<link rel="icon" href="data:,">
</head>
<body>
<div class="app-shell">
    <header class="topbar">
        <a href="index.php" class="brand">
            <span class="brand-mark">◎</span>
            <span><?= htmlspecialchars(APP_NAME) ?></span>
        </a>

        <nav class="main-nav">
            <a href="index.php" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
            <a href="contracts.php" class="<?= $activeNav === 'contracts' ? 'active' : '' ?>">Verträge</a>
            <a href="payments.php" class="<?= $activeNav === 'payments' ? 'active' : '' ?>">Zahlungen</a>
            <a href="price_changes.php" class="<?= $activeNav === 'prices' ? 'active' : '' ?>">Preise</a>
            <a href="compare.php" class="<?= $activeNav === 'compare' ? 'active' : '' ?>">Vergleich</a>
            <a href="categories.php" class="<?= $activeNav === 'categories' ? 'active' : '' ?>">Kategorien</a>
            <a href="export.php" class="<?= $activeNav === 'export' ? 'active' : '' ?>">Export</a>
        </nav>

        <div class="topbar-actions">
            <a href="contract_edit.php" class="btn btn-primary btn-sm">+ Neuer Vertrag</a>
            <form method="post" action="logout.php" class="inline-form"><?= csrf_field() ?><button type="submit" class="icon-btn" title="Abmelden" aria-label="Abmelden">⏻</button></form>
        </div>

        <button class="nav-toggle" type="button" aria-label="Menü" onclick="document.querySelector('.main-nav').classList.toggle('open')">☰</button>
    </header>

    <main class="app-main">
    <?php foreach (pull_flashes() as $flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
