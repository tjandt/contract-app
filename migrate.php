<?php
/**
 * Datenbank-Migrationen einspielen (Neuinstallation und Updates).
 *
 *   php migrate.php        # auf dem Server (CLI)
 *   migrate.php            # im Browser, nach dem Login
 */
define('APP_SKIP_SCHEMA_CHECK', true);
require_once __DIR__ . '/includes/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    require_login();
}

$log = [];
$error = null;
$ran = false;

if ($isCli || $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isCli) {
        csrf_verify();
    }
    try {
        $log = run_pending_migrations(get_pdo());
        $ran = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($isCli) {
    echo implode(PHP_EOL, $log), PHP_EOL;
    if ($error) {
        fwrite(STDERR, 'Fehler: ' . $error . PHP_EOL);
        exit(1);
    }
    exit(0);
}

$current = schema_version(get_pdo());
$latest = latest_schema_version();
$pageTitle = 'Datenbank-Update';
$activeNav = '';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Datenbank-Update</h1>
        <p>Schema-Stand: Version <?= $current ?> von <?= $latest ?></p>
    </div>
</div>
<div class="card">
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($ran): ?>
        <div class="alert alert-success"><?php foreach ($log as $line): ?><div><?= e($line) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if ($current < $latest): ?>
        <p class="text-secondary">Es stehen <?= $latest - $current ?> Migration(en) aus. Bitte vorher ein Datenbank-Backup anlegen.</p>
        <form method="post"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Jetzt aktualisieren</button></form>
    <?php else: ?>
        <p>Die Datenbank ist auf dem aktuellen Stand.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
