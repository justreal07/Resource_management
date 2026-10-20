<?php
// Starts the session with safe cookie settings. Used by config.php and by pages that start
// the session without loading config.php (such as the applicant logout page), so every
// session cookie the site hands out has the same settings.
//
// httponly   - scripts on the page can't read the cookie
// SameSite   - other websites can't send it along with their own requests
//              (Lax still lets the Google sign-in redirect back in)
// secure     - only sent over HTTPS when the site runs on HTTPS
if (session_status() === PHP_SESSION_NONE) {
    // Strict mode: PHP refuses a session id it did not make itself (otherwise an attacker could hand a visitor a
    // known id and wait for them to sign in). Ids travel only in the cookie, never in an address.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
