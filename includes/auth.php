<?php
/**
 * Einfacher, passwortbasierter Zugangsschutz (kein Multi-User-System).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_logged_in(): bool
{
    return !empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

/** Sehr simples Rate-Limiting gegen Brute-Force über die Session. */
function too_many_attempts(): bool
{
    $attempts = $_SESSION['login_attempts'] ?? 0;
    $lastAttempt = $_SESSION['login_last_attempt'] ?? 0;

    // Nach 5 Minuten Zähler zurücksetzen
    if (time() - $lastAttempt > 300) {
        $_SESSION['login_attempts'] = 0;
        return false;
    }
    return $attempts >= 8;
}

function register_failed_attempt(): void
{
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
    $_SESSION['login_last_attempt'] = time();
}
