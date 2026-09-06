# KainchiDarshan Admin

A Hostinger-ready admin console built with PHP 8+, MySQL/MariaDB, semantic HTML, CSS, and vanilla JavaScript modules. No build step or Node process is required.

## Deploy on Hostinger

1. Upload the project contents into `public_html/admin` on the same Hostinger website as the frontend. Do not overwrite the frontend's root files.
2. Create a MySQL database and user in hPanel and import `database.sql` in phpMyAdmin.
3. For an existing Pahadi Stay database, do not import `database.sql`; import `admin_migration.sql` instead to add only admin settings and audit tables.
3. Copy `config.example.php` to `config.php` and fill in the database credentials. Keep `config.php` outside the public directory when possible; otherwise the included rules deny direct access.
4. Create `../uploads/images` relative to the admin directory, normally `public_html/uploads/images`, and make it writable by PHP. Keep private files outside executable PHP paths.
5. Enable HTTPS, set `secure_cookies` to `true`, and open `/admin/` on the existing domain.
6. Open `/admin/` and enter one of the three allowlisted admin emails configured in `api/lib.php`. No password is used by this login flow.
7. Add a cron job to call `cron/publish_scheduled.php` every five minutes if scheduled blog publishing is needed.

## Local smoke test

With PHP installed, run `php -S localhost:8080 -t .` and open `http://localhost:8080/admin/`. Without a configured database, the shell still loads and shows a clear degraded state; API writes require the configured MySQL connection.

The API uses sessions, HttpOnly/SameSite cookies, CSRF tokens, prepared PDO statements, server-side authorization, audit events, JSON error envelopes, and server-side pagination. Never commit `config.php` or store payment secrets in this project.
