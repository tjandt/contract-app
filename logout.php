<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

// Abmelden nur per POST mit CSRF-Token, damit fremde Seiten niemanden per Link/Bild ausloggen können
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

header('Location: index.php');
exit;
