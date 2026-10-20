# REST API usage

Base: `http://127.0.0.1:8013/api/v1`. Import `openapi.json` or `postman.json`. The specification is generated from registered routes with explicit expanded action endpoints and documents inputs/authorization. Confirm operation response details against the running API; additional typed response schemas are future work.

## Authentication

First-party Vue uses Laravel session cookies and CSRF. The page includes a CSRF meta token. POST `/login` on the web origin, then call `/api/v1/auth/me`. The configured Sanctum stateful domains must include the exact local host and port. Use the same hostname consistently.

Flutter: POST `/auth/mobile/login` with `email`, `password`, `device_name`; use `data.token` as a Bearer token. Expiry is 30 days; logout and device revocation invalidate tokens. There is no refresh-token endpoint. Staff and admin share authentication but server permissions differ.

## Conventions

- Mutations that allocate rooms or post financial state require `Idempotency-Key`; the specification marks each route. Keep the same key and identical serialized body for a retry. Different body or path with the same actor/key returns 409.
- Booking transitions require the current integer `version`. Reload after 409 and review changes.
- Money is integer minor units: `125000` means BDT 1,250.00, VAT included.
- Input timestamps should include `+06:00`. URL-encode plus signs in query strings. Controllers normalize dates to Dhaka time. Current booking timestamp responses are SQL-style local strings; parse them explicitly as Asia/Dhaka. Other Laravel timestamps can be ISO UTC.
- Explicit resources generally return `data`. Booking detail additionally returns `finance`. Laravel paginated collections provide `data`, links and pagination fields. Follow `next_page_url` rather than assuming the first page is complete.
- 401 unauthenticated; 403 forbidden; 404 absent resource; 409 conflict/stale state; 422 invalid fields; 429 throttled. Validation errors include message, code, errors and request_id. Not every framework-generated error currently has the same expanded envelope.
- No guest/public self-booking endpoints exist.

## Example booking

```json
{
  "guest_id": 1,
  "guest_category_id": 1,
  "room_ids": [2],
  "check_in_at": "2027-01-01T14:00:00+06:00",
  "check_out_at": "2027-01-05T12:00:00+06:00",
  "reference_letter": "OFFICE/2027/001",
  "guest_count": 1
}
```

POST `/booking-quotes` to preview; guest_id is optional for a quote. POST `/bookings` creates a draft. POST `/bookings/{id}/submit` with `{"version":1}` requests approval. Admin POSTs `/bookings/{id}/approve` with the latest version. Admin may confirm their own draft directly. A provisional tariff cannot approve a booking.

Staff can edit their own draft/rejected requests through PATCH `/bookings/{id}` with the full booking input plus version. Confirmed bookings use `/amendments` with full proposed input, version and reason. Approval of an unpaid pre-arrival amendment supersedes the old invoice and replaces the allocation atomically.

## Reservations and calendar

POST `/room-blocks` with kind (`reservation` or `maintenance`), reason, room_ids, starts_at, ends_at. Approve a booking with `reservation_id` to atomically convert a matching active reservation. The rooms must match exactly and booking dates must fit inside the reservation.

GET `/availability?check_in_at=...&check_out_at=...` returns each room's available flag and conflicts. GET `/calendar?start=YYYY-MM-DD&end=YYYY-MM-DD` returns blocking entries plus pending/confirmed/occupied bookings. End is exclusive. Maximum calendar span is 93 days.

## Finance

POST `/bookings/{id}/payments` with amount_minor, method (cash/bank_transfer/other), optional reference. POST `/payments/{id}/refunds` with amount_minor/reason or `/reverse` with reason. POST `/bookings/{id}/credit-notes` reduces issued charges with amount_minor/reason. All require admin and idempotency keys.

An outstanding checkout requires a reason. Payments never release rooms. Refunds cannot exceed the remaining posted payment. Payments with refunds cannot be reversed. Original invoice records are preserved.

## Private documents

POST `/bookings/{id}/attachments` using multipart file while the booking is draft/rejected. PDF/JPG/PNG up to 5 MB. GET `/attachments/{id}/download` requires active staff/admin authentication. Paths are never publicly served.

## Endpoint inventory

See OpenAPI for all registered operations. Core endpoints cover auth/devices, catalog, dashboard, guests, availability/calendar, booking lifecycle/amendments, rooms, reservations/maintenance, tariffs/rates, payments/refunds/credits, staff, attachments, notifications, reports and audits. Infrastructure tables intentionally have no public CRUD API.


## Admin editing after approval

Open Booking Details, choose **Edit booking**, edit the guest snapshot, dates, rooms, category, approved tariff, reference information, guest count, purpose or notes, enter a reason and click **Save changes**. This is admin-only and retains approval/occupancy status. Staff still use amendment proposals. Payments and issued invoices are preserved; price increases add an adjustment invoice and reductions create credit notes. An overpaid balance remains available for a separately authorized refund. Completed stays do not re-block rooms, and occupied transfers require ready rooms. Optional guest notification sends the revised confirmation. The REST endpoint is `PATCH /api/v1/bookings/{id}/approved-details`, requiring full booking input, current version, reason, optional guest_details and notify_guest, plus Idempotency-Key.

## New features and Postman collection (October 2026)

The collection now contains 95 method/path operations, checked against Laravel routes. Import `docs/postman.json`, set `base_url`, `email` and `password`, and send **POST /auth/mobile/login** first; its test stores the bearer token. Set `id` for reference entities and `booking_id` for bookings. Booking detail/mutation responses update `booking_version`. Use the actual current version. Collection examples are templates; select real room/category/guest IDs and review all mutation bodies before sending. A full collection run is not a safe read-only health check.

- `GET /bookings/{id}/guest-confirmation` returns guest-safe information, including room number and name; internal notes are excluded.
- `GET /bookings/{id}/exports/pdf` and `/exports/jpg` return private binary files. Send the bearer token and save response bytes, rather than decoding JSON. No publicly usable download URLs are generated.
- `GET /bookings/{id}/share-options` returns WhatsApp/mail compose links and authenticated download URLs. Links open a composer; the operator confirms sending. Tokens must never be added to a shared URL.
- `POST /bookings/{id}/share` accepts `channel` (email/sms/whatsapp), `format` (text/pdf/jpg; default text) and current `version`, with Idempotency-Key. Destination is the guest saved in the booking snapshot. Email supports actual PDF/JPG attachments. WhatsApp API uses an approved template; SMS uses text. For WhatsApp file attachments, download the PDF/JPG and use the device's native share sheet. Arbitrary recipient overrides are rejected.
- Sharing returns **202 queued**, not delivered. `GET /bookings/{id}/shares` exposes delivery history. Configure SMTP or Twilio, the scheduler and queue worker. Unconfigured delivery returns 503. A queued manual share is cancelled when the booking version changes; request a new share with current data.
- `GET/PATCH /auth/preferences` reads/saves the authenticated user's `theme` (light/dark/system) and `language` (en/bn). The website also syncs these preferences. Icons, responsive layouts and modal sizing are client presentation, not separate REST resources.
- `POST /auth/forgot-password` and `/auth/reset-password` expose password recovery to mobile clients. SMTP is required to deliver reset links. Reset revokes existing mobile tokens.
- `GET /reports/export?start=YYYY-MM-DD&end=YYYY-MM-DD` returns an admin-only CSV file. Guest/reference master reads and tariff list/detail are also available.

PDF/JPG rendering requires `npm install` (including puppeteer-core), Node.js and an installed Chrome/Chromium/Edge. Set EXPORT_NODE_BINARY and EXPORT_BROWSER_BINARY if executable discovery is unavailable. No browser is downloaded automatically. Renderer input is escaped, remote assets and JavaScript are disabled, and temporary files are private and removed after rendering. The export service restores the Windows environment required by Node/Chromium; it was verified with the ordinary Laravel development server. Production should use the configured PHP web server and queue worker.

For new idempotent actions leave `retry_idempotency_key` blank. For a retry, pin the original key and preserve the identical body. Reusing a key with another action/body returns a conflict. Do not run provider webhooks manually without a correct Twilio signature. See `API-COVERAGE.md` for the complete inventory; regenerate both JSON artifacts with `python tools/generate-api-docs.py` after route/schema changes.


Release 1.2.0 adds public GET /api/v1/version. Existing v1 endpoints remain unchanged. See [API versioning and deployment](API-VERSIONING.md). The regenerated Postman collection defaults to the production domain; change base_url for development.
