<?php
/**
 * Einfacher, passwortbasierter Zugangsschutz (kein Multi-User-System).
 */

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
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

// ------------------------------------------------------------------
// CSRF-Schutz für alle schreibenden Formulare (POST)
// ------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Verstecktes Formularfeld mit dem CSRF-Token. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/** Bricht ab, wenn der POST-Request kein gültiges CSRF-Token mitbringt. */
function csrf_verify(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        die('Ungültiges oder abgelaufenes Formular. Bitte die Seite neu laden und erneut versuchen.');
    }
}
