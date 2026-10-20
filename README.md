# Cox's Bazar Rest House

Laravel 13 / PHP 8.3+ / Vue 3 / TypeScript / MySQL 8 administrative booking application.

## Installed locally

Project: `G:\laragon\www\coxbazar-banglo\cox-laravel-application`.
Database: `cox-laravel-application`. The initial database was empty; only additive application migrations and source reference seeders were run.
Preview: http://127.0.0.1:8013 . Run `php artisan serve --host=127.0.0.1 --port=8013` from this folder to restart it.

Login email: `admin@cox-resthouse.test`. The generated password is in the **private local file** `storage/app/local-admin-credentials.txt`. Change it in Account after signing in. Do not publish that file or use the development MySQL root account in production.

## First use

1. Sign in. In Tariffs, choose the authorized progressive or whole-stay calculation policy, effective date, standard arrival/departure times, turnaround and communication channel. Read the policy acknowledgement before activating.
2. In Rooms, verify each capacity and whether it is bookable. Mark rooms ready only after inspection. R102 requires particular verification because the source names it a waiting room.
3. Add staff through Staff. Staff can create guests, enter drafts and submit them; only admin can approve.
4. Add a guest, create a booking, select dates/rooms and review the quote. Save and submit. Admin approval allocates all rooms atomically.
5. Use Check in at arrival. Record verified payments. Checkout ends occupancy; cleaning readiness is a separate room update.

## Run locally

```powershell
composer install
npm.cmd install
# For a fresh copy only: copy .env.example .env; then configure database and APP_URL.
php artisan key:generate
php artisan migrate --seed
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8013
```

For development assets: `npm.cmd run dev` in another terminal. For background messages: `php artisan queue:work --tries=4` and `php artisan schedule:work` in separate terminals.

Do not regenerate APP_KEY on an established installation. Never use `migrate:fresh` against the application database.

## Included

- Reference inventory: 14 rooms, three floors, three guest categories and all 2026 VAT-inclusive rate bands.
- Staff drafts, submission, admin approval/rejection/return, cancellation and no-show handling.
- Date-sensitive availability, room cards and day/week/30-day room calendar.
- Fixed-time admin reservations, maintenance blocks and atomic reservation conversion at approval.
- Multi-room all-or-nothing allocation, row locks, MySQL current-read conflict checks, optimistic versions and idempotency keys.
- Check-in, checkout, cleaning states, reference attachments and immutable booking guest snapshots.
- Progressive and whole-stay quotes, versioned approved tariffs, rate cloning and immutable historical invoice lines.
- Payments, partial balances, receipts, reversals, refunds and credit notes; print booking/invoice information.
- Pre-arrival amendment proposals and approval. Paid bookings require financial review before amendment.
- Staff account administration, audit trail, reports with CSV export, notification delivery history.
- Session-based web authentication and expiring/revocable mobile bearer tokens.
- Vue responsive website with Bangla navigation, Bangla room names and localized dates/currency. Some operational text remains English.
- Versioned REST APIs, OpenAPI specification and Postman collection.

## External configuration and deliberate limits

- Email sending and password recovery require real SMTP settings. Default `MAIL_MAILER=log` does not deliver email. Guest confirmation jobs explicitly report unconfigured delivery rather than pretending a message was sent.
- SMS and WhatsApp adapters are installed for Twilio, with signature-verified delivery callbacks. Real credentials, an approved sender, a public HTTPS callback URL and an approved WhatsApp Content template are required before live sending. Provider delivery has only been tested with mocked responses.
- Initial roles are fixed admin/staff; granular delegated operational permissions and MFA are future enhancements.
- Same-day stays are rejected. Extensions, transfers and price changes after check-in, and amendments after posted payments, require a later reviewed financial/operational workflow.
- No online payment gateway or Flutter UI is included. All Flutter clients must use the provided API. See `docs/FLUTTER.md`.
- No discounts or invented suite surcharge. VAT is already included. Consecutive-stay limits require manual admin review until the office approves continuity rules.
- Property, building, floor, room and category masters have authenticated read APIs and admin create/update APIs. These expansion endpoints are available for future clients; the initial website focuses on managing the seeded rest house. Rate-band restructuring, detailed occupancy percentages and full interface translation remain follow-up work.
- Email is at-least-once delivery: a crash after provider acceptance but before local persistence can cause an uncertain delivery. Verify before manual resend. A provider with idempotent requests and verified callbacks is needed for stronger delivery guarantees.

## Tests

```powershell
php artisan test
# Create the isolated test DB first; never point this configuration at live data.
mysql -u root -e "CREATE DATABASE IF NOT EXISTS cox_laravel_application_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
php vendor/bin/phpunit --configuration phpunit.mysql.xml
php tools/check-concurrency.php
```

The default suite uses SQLite memory. The MySQL suite uses only `cox_laravel_application_testing`. The concurrent tool refuses any other database and recreates only that disposable test schema. It starts two PHP processes approving conflicting requests and requires exactly one success and one HTTP-style 409 conflict.

## Documentation

- `docs/ARCHITECTURE.md`: schema and business rules.
- `docs/OPERATIONS.md`: admin/staff guide, deployment, backup and recovery.
- `docs/API.md`, `docs/openapi.json`, `docs/postman.json`: REST integration.
- `docs/FLUTTER.md`: mobile client implementation guidelines.
- `docs/MESSAGING.md`: SMTP, Twilio SMS/WhatsApp and signed callback configuration.

The application is installed for local development and review. Complete office policy approval, provider setup and production hardening before operational deployment.


## Admin editing after approval

Open Booking Details, choose **Edit booking**, edit the guest snapshot, dates, rooms, category, approved tariff, reference information, guest count, purpose or notes, enter a reason and click **Save changes**. This is admin-only and retains approval/occupancy status. Staff still use amendment proposals. Payments and issued invoices are preserved; price increases add an adjustment invoice and reductions create credit notes. An overpaid balance remains available for a separately authorized refund. Completed stays do not re-block rooms, and occupied transfers require ready rooms. Optional guest notification sends the revised confirmation. The REST endpoint is `PATCH /api/v1/bookings/{id}/approved-details`, requiring full booking input, current version, reason, optional guest_details and notify_guest, plus Idempotency-Key.


Responsive interface details and verified phone/tablet sizes: see `docs/RESPONSIVE.md`.
