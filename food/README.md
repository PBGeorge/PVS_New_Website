# Food Log — install on your Namebox (cPanel) hosting

A small, self-contained PHP + MySQL app. No build step, no Composer, no
command line. You upload the files, create a database, edit one config
file, and create your two accounts.

It lives in its own subfolder, so it won't touch your existing website.

---

## 1. Create the database (cPanel → MySQL® Databases)

1. **Create New Database** — e.g. `foodlog`. cPanel prefixes it with your
   account name, so the real name becomes something like `yourusr_foodlog`.
2. **Add New User** — e.g. `food`, with a strong password. Real name
   becomes `yourusr_food`.
3. **Add User To Database** → select that user and database →
   **ALL PRIVILEGES**.

Write down the three values: database name, username, password.
The host is almost always `localhost`.

## 2. Edit `config.php`

Open `config.php` and fill in:

```php
define('DB_NAME', 'yourusr_foodlog');
define('DB_USER', 'yourusr_food');
define('DB_PASS', 'the-password-you-set');
define('APP_TIMEZONE', 'Europe/Bucharest'); // adjust if needed
```

## 3. Upload the files (cPanel → File Manager)

1. Go into `public_html`.
2. Create a folder, e.g. `food`.
3. Upload **all** the files from this package into `public_html/food/`
   (the included `food-log.zip` can be uploaded and extracted in place —
   make sure the hidden `.htaccess` comes along; enable "Show Hidden Files"
   in File Manager settings if needed).

The tables are created automatically the first time a page loads — you
don't run any SQL yourself.

## 4. Check the PHP version (cPanel → Select PHP Version)

Set it to **PHP 8.0 or newer** if it isn't already.

## 5. Create your two accounts

1. Visit `https://yourdomain/food/setup.php`.
2. Enter a display name, username, and password (8+ chars) for each of the
   two people.
3. **Then delete `setup.php` from the server** — the page reminds you. This
   stops anyone from re-running setup.

## 6. Use it

Go to `https://yourdomain/food/` and sign in.

- **Add meal** → dish, time, location (Home/Restaurant), optional place,
  an ingredient list (quantity + how it was prepared), and notes.
- Tap **Edit** / **Delete** on any meal.
- **Export** (top right) downloads an `.xlsx` of everything — one row per
  ingredient, with the dish, time, location and notes alongside.

Both accounts see the same shared diary; each meal is tagged with who
added it.

---

## Notes

- **Backups:** your food data lives in the MySQL database. cPanel's
  backups cover it, or use phpMyAdmin → Export now and then.
- **Changing a password later:** the simplest route is phpMyAdmin — but
  passwords are hashed, so you'd update the `password_hash` value with a
  new `password_hash()` output. Ask me and I'll give you a one-line
  snippet, or I can add a small "change password" screen.
- **Want per-person diaries instead of a shared one?** It's a small
  change — say the word.
