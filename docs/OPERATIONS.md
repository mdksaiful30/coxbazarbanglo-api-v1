# Operations, deployment and recovery

## Daily staff workflow

1. Add/search a guest; check designation, organization, mobile and category evidence.
2. Create a draft with dates, room selections and reference letter. Review the itemized quote.
3. Upload a permitted PDF/JPG/PNG reference letter (up to 5 MB) while still a draft.
4. Submit for admin approval. A pending request does not reserve a room.
5. If returned/rejected, edit, save and resubmit.
6. For confirmed pre-arrival changes, propose an amendment. The existing allocation remains until approval.

## Admin workflow

Review pending requests, eligibility, live availability and amount. Approve or reject/return with a reason. At approval, optionally enter an existing reservation ID to convert an exact matching room set within the reserved interval. All allocation changes commit together.

Check guests in only within the approved interval and when rooms are ready. A still-occupied room cannot be checked in to a new guest. Record verified payments and retain receipts. Checkout with debt requires a reason. After checkout, set each room's housekeeping state to ready only after cleaning.

Cancellation/no-show releases the interval but preserves issued charges. Issue a credit for an approved waiver; record a separate refund when returning money. Neither payment nor credit is a checkout action.

## Local administrators

The first generated local login is documented in `storage/app/local-admin-credentials.txt`, which must remain private. New admins/staff are created by an existing admin through Staff, or interactively with:

`php artisan resthouse:create-admin email@example.com --name="Administrator Name"`

The command prompts for a password and refuses to overwrite an existing account. Never commit `.env`, local credentials, storage data or database dumps. Existing admin accounts cannot disable or demote themselves through the staff screen.

## Notifications

Configure SMTP using `MAIL_MAILER=smtp`, host, port, username, password, encryption and verified sender values required by your provider. Then clear cached configuration. Do not use placeholder sender addresses in production.

Run scheduler and queue worker continuously. Approval inserts an outbox record; the scheduler dispatches queued records. Retries use 60/300/900-second backoff. Review failed delivery history and repair configuration before retrying. No SMTP configuration means no message was delivered.

SMS/WhatsApp use the installed Twilio adapter. Configure TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_SMS_FROM, TWILIO_WHATSAPP_FROM, TWILIO_WHATSAPP_CONTENT_SID and TWILIO_STATUS_CALLBACK in .env. The callback must be the exact public HTTPS URL ending /api/v1/messaging/twilio/status. The WhatsApp approved template variables are 1=booking number, 2=arrival, 3=departure, 4=room codes, 5=BDT amount. Confirm recipient consent and sender/template approval with your provider. Provider acceptance is recorded separately from delivered/read status. Callbacks validate the original form body with the official SDK signature validator. Real delivery is not verified until you supply credentials and test on staging. No paid messages were sent during installation. Provider-accepted failures require reconciliation before any deliberate resend.

## Production deployment

1. Use a supported PHP release with PDO MySQL, mbstring, intl, XML, curl, OpenSSL and fileinfo. Use MySQL InnoDB and UTF-8.
2. Deploy source; run `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build`.
3. Create a least-privilege database account for the application. Replace development root credentials.
4. Set `APP_ENV=production`, `APP_DEBUG=false`, correct HTTPS `APP_URL`, secure cookies, stateful domains and session domain. Configure trusted reverse proxies for the actual deployment.
5. Point Apache/Nginx document root at **public/**, never the project root. Keep storage/app and .env inaccessible. Remove any cached development `public/hot` file.
6. Keep the established APP_KEY. Make storage and bootstrap/cache writable by the application service account.
7. Back up before schema changes; run `php artisan migrate --force`. Seed reference data only as intended; seeders preserve existing room verification and approved tariffs.
8. Run `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache` after validation.
9. Run supervised `php artisan queue:work --tries=4 --timeout=60`, plus one `php artisan schedule:run` every minute using the OS scheduler.
10. Test SMTP, password reset, queue recovery, room conflicts, role permissions, financial reconciliation, uploads and print output on staging.
11. Monitor `/up`, errors, failed jobs, stuck processing notifications, backup age and resource use. Health endpoint alone does not prove queues or providers work.
12. Apply the office's approved data-retention policy. Add MFA, detailed delegated permissions and full Bangla translations before a rollout that requires them.

## Backup

Use the installed `mysqldump` with `--single-transaction --routines --triggers` and `--result-file` to avoid PowerShell redirection encoding problems. For this hyphenated schema name, pass `cox-laravel-application` as one argument. Supply credentials through a protected option file or secure prompt, not shell history. Store dumps outside the web root with restricted access and encrypt off-site copies.

Back up private uploaded files and preserve APP_KEY separately. Define recovery point and recovery time targets with the owner. Take nightly backups plus pre-deployment backups and monitor successful completion.

## Restore and rollback

Restore first into a separate staging database, using the mysql client's native source/import workflow. Restore matching private files and APP_KEY, configure the staging application, run a read-only smoke test and reconcile invoice/payment totals against the backup report. Never test restoration over the live schema.

For deployment rollback, stop writes/workers, restore the prior application release and compatible database backup when required. Do not blindly run migration rollback on financial tables. Restart workers and verify confirmed allocations and balances before reopening writes.

A backup/restore drill has not been performed against your production infrastructure. Perform and document it before launch.


## Admin editing after approval

Open Booking Details, choose **Edit booking**, edit the guest snapshot, dates, rooms, category, approved tariff, reference information, guest count, purpose or notes, enter a reason and click **Save changes**. This is admin-only and retains approval/occupancy status. Staff still use amendment proposals. Payments and issued invoices are preserved; price increases add an adjustment invoice and reductions create credit notes. An overpaid balance remains available for a separately authorized refund. Completed stays do not re-block rooms, and occupied transfers require ready rooms. Optional guest notification sends the revised confirmation. The REST endpoint is `PATCH /api/v1/bookings/{id}/approved-details`, requiring full booking input, current version, reason, optional guest_details and notify_guest, plus Idempotency-Key.


## Downloading and sharing Booking Details

Admin and staff-admin can open Booking Details and use PDF, JPG or Share at the top. PDF creates an A4 document; JPG creates a high-resolution image. Both include guest details, date/time in Dhaka, room numbers and names, status and the current payment summary. Internal notes, audit history, staff IDs and private reference attachments are excluded. Draft/pending/cancelled records are labeled clearly and are not represented as confirmed reservations.

Share > WhatsApp guest opens a prefilled message addressed to the saved guest mobile number. Share > Email guest opens the local email composer addressed to the saved email. The operator reviews and sends; neither action automatically sends a message. No public guest link is exposed.

To send the exported file, choose Prepare PDF attachment. Supported browsers offer a second Share prepared PDF action to open the device share sheet. Otherwise a PDF is downloaded for manual attachment. WhatsApp links and mailto links cannot automatically attach local files; use the downloaded PDF/JPG in those apps. Missing guest contact details are identified in the menu. Automatic SMTP/Twilio approval notifications remain separate.
