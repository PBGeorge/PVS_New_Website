<?php
// ============================================================
//  Food Log — configuration
//  Edit the values below to match your cPanel setup, then save.
//  This is the ONLY file you need to change to go live.
// ============================================================

// --- Database (cPanel → MySQL Databases) ---
define('DB_HOST', 'localhost');        // almost always 'localhost' on cPanel
define('DB_NAME', 'yourusr_foodlog');  // the database you created
define('DB_USER', 'yourusr_food');     // the database user you created
define('DB_PASS', 'CHANGE_ME');        // that user's password

// --- App ---
define('APP_NAME',     'Food Log');
define('APP_TIMEZONE', 'Europe/Bucharest'); // your timezone

// Force HTTPS. Your host has SSL, so leave this true.
define('FORCE_HTTPS', true);
