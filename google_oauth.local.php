<?php
/**
 * Google sign-in credentials (read by config.php).
 *
 * 1. Google Cloud Console > APIs & Services > Credentials > Create OAuth client ID > "Web application".
 * 2. Under "Authorized redirect URIs" add EXACTLY:
 *        http://localhost/Resource_management/Resource_management/user/google_callback.php
 *    (that is where this project lives inside htdocs; change it if you move the folder).
 * 3. Paste the Client ID and Client secret below and save. No restart needed.
 *
 * Leave GOOGLE_REDIRECT_URI empty to have it detected automatically.
 * Keep this file private: don't commit it or share it.
 *
 * NOTE: the redirect URI currently registered in Google Cloud for this client is the OLD path
 * (http://localhost/Resource_management/user/google_callback.php). That path is served by
 * htdocs/Resource_management/user/google_callback.php, which forwards to this project's callback.
 * If you add the new URI from step 2 in Google Cloud, set GOOGLE_REDIRECT_URI back to ''.
 */
return [
    'GOOGLE_CLIENT_ID'     => '349310716434-pprha5iiov8v3io7s0muj6kbckkgunis.apps.googleusercontent.com',
    'GOOGLE_CLIENT_SECRET' => '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',
    'GOOGLE_REDIRECT_URI'  => 'http://localhost/Resource_management/user/google_callback.php',
];
