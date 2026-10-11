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
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
