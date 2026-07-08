<?php
// ============================================================
//  Food Log — configuration
//  Edit the values below to match your cPanel setup, then save.
//  This is the ONLY file you need to change to go live.
// ============================================================

// --- Database (cPanel → MySQL Databases) ---
define('DB_HOST', 'localhost');        // almost always 'localhost' on cPanel
define('DB_NAME', 'powervan_foodlog');  // the database you created
define('DB_USER', 'powervan_gp');     // the database user you created
define('DB_PASS', '6P1F&#c?,tW0L&{h');        // that user's password

// --- App ---
define('APP_NAME',     'Food Log');
define('APP_TIMEZONE', 'Europe/Bucharest'); // your timezone

// Force HTTPS. Your host has SSL, so leave this true.
define('FORCE_HTTPS', true);

// --- Email (for password-reset links) ---
// Use the same cPanel SMTP account as the main site (see db_config.php).
// Reset emails will not send until SMTP_PASS is filled in.
define('SMTP_HOST',      'cl83.namebox.ro');                       // your mail server hostname
define('SMTP_PORT',      465);                                     // 465 = SSL, 587 = STARTTLS
define('SMTP_USER',      'noreply@powervantagesolutions.com');     // full email address
define('SMTP_PASS',      'qju_TKV.fxz5fya.eph');                             // that mailbox's password
define('SMTP_FROM',      'noreply@powervantagesolutions.com');     // "from" address
define('SMTP_FROM_NAME', 'Food Log');                             // "from" display name


// --- Gemini (calorie estimation) ---
// gemini-2.5-flash-lite: current, fast and cheap, good enough for kcal
// estimates. (gemini-2.0-flash was retired; gemini-flash-latest points at a
// slower "thinking" model.) If Google retires this one too, the error will
// read "model ... no longer available" — swap in the next flash-lite here.
define('GEMINI_MODEL', 'gemini-2.5-flash-lite');

// The API key is kept OUT of this repo so it never lands on GitHub.
// Create food/secrets.php on the server (cPanel File Manager) containing:
//     <?php define('GEMINI_API_KEY', 'your-key-here');
// It is gitignored, so deploys/pulls never overwrite or expose it.
$secretsFile = __DIR__ . '/secrets.php';
if (is_file($secretsFile)) require $secretsFile;
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', ''); // blank = estimation off