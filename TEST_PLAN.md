# Acceptance checklist

Run these checks after importing `database.sql` on Hostinger:

- `GET /api/admin/auth/csrf` returns a CSRF token without an authenticated user.
- The three allowlisted emails can log in without a password; any other email returns 401.
- `GET /api/admin/dashboard` returns 401 before login and dashboard data after login.
- Missing or incorrect `X-CSRF-Token` returns 419 for mutations.
- STAFF receives 403 when saving settings.
- OWNER can create and update a listing; negative prices and a selling price below base price return 422.
- Listing deletion with referenced bookings returns 409.
- Search and pagination return `meta.page`, `meta.perPage`, `meta.total`, and `meta.pages`.
- Order transitions reject invalid state changes with 409 and write an audit event.
- Scheduled blog cron publishes only rows whose schedule is due.
- Mobile viewport at 375px keeps navigation usable and tables scroll inside their panel.
- Keyboard focus is visible on inputs, buttons, navigation, and drawer controls.

For PHP syntax, run `php -l` over `*.php` and `api/*.php`. For a local smoke test, run `php -S localhost:8080 -t .` and open `/admin/`.
