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
