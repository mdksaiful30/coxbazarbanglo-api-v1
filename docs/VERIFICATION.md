# Installation verification

- Laravel 13.37.0, PHP 8.5.8, MySQL 8.4.3, Vue 3 and TypeScript installed.
- Main schema migrated and seeded: 14 rooms, 3 categories, 14 tariff rate rows; no sample guest bookings inserted.
- Production frontend type-check and build passed.
- Feature suite: 32 tests, 120 assertions against SQLite memory and the isolated MySQL database.
- Independent two-process MySQL approval test: one confirmed booking and one 409 conflict; exactly one active allocation.
- Browser sign-in, dashboard, tariff policy review and all floor/room cards checked.
- SMTP/Twilio credentials were not supplied; real external messaging was not tested or sent. Messaging tests use fake HTTP responses and test signatures only.
- Backup/restore, public HTTPS deployment, actual device Flutter integration and production provider delivery still require environment-specific validation.

## REST completion verification — 18 October 2026

- 95 expanded method/path API operations match the generated OpenAPI and Postman inventories.
- 47 tests / 233 assertions passed on SQLite and isolated MySQL, including new preferences, private exports, guest-safe sharing, stale snapshot cancellation, idempotency, recipient restrictions, provider configuration, CSV permissions/formula escaping and password reset/token revocation.
- Website type checking and production build passed.
- Installed Postman CLI: isolated local smoke run passed 15 requests / 19 assertions, including actual PDF/JPG downloads, preferences, booking details, safe sharing options/history, tariff reads and CSV export. Provider sends were not executed. Run results were not published to Postman cloud.
- Actual JPG was visually inspected and PDF content validated. Two concurrent conflicting approvals still resulted in exactly one confirmed booking and one conflict.

Live provider credentials, delivery receipts and production deployment require environment-specific verification. Tests use the disposable `cox_laravel_application_testing` database; production booking data is preserved.
