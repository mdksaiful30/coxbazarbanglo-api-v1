# Flutter integration guide

The Flutter application is a later client of this backend; no separate booking database or pricing engine belongs in Flutter.

## Structure

Use `features/auth`, `dashboard`, `availability`, `guests`, `bookings`, `approvals`, `payments` and `account`. Each feature can separate API DTOs, repository, state/controller and screen layers. Use a shared Dio client and one state management approach such as Riverpod. Keep API base URL in build environment configuration.

## Authentication

POST `/api/v1/auth/mobile/login` with email, password and device_name. Read `data.token`, `data.expires_at` and `data.user`. Store the token using platform secure storage. Send `Authorization: Bearer <token>`, `Accept: application/json` and JSON request bodies. On 401, clear auth state and require sign-in. This is a revocable Sanctum token, not a JWT refresh-token system. Expiry is 30 days. Logout revokes the current token; devices endpoint lists/revokes owned devices.

For Android emulator development the host is normally `http://10.0.2.2:8013`, not the emulator's own 127.0.0.1. Physical devices need the development computer's reachable address and explicit local server/network configuration. Release builds must use HTTPS and the production hostname. Never include database credentials or admin passwords in Flutter.

## Booking workflow

1. Load `/catalog` for rooms, categories, tariffs and operations.
2. Query `/availability` with explicit arrival/departure offsets, such as `2027-01-01T14:00:00+06:00`.
3. Find/create a guest.
4. Request `/booking-quotes`. Display provisional flag and itemized amounts; the server remains authoritative.
5. POST `/bookings` with selected room IDs and booking fields. Generate a UUID Idempotency-Key and retain it until the operation outcome is known.
6. Submit with the returned booking `version`. Admin approves through the same API; staff cannot self-approve.
7. On 409, reload booking/availability and ask the operator to resolve the conflict. Do not retry blindly with a new idempotency key.

## Types and errors

Money is integer poisha: display amount_minor / 100 with BDT formatting. Store amounts as integers. Parse timestamps with their offset; domain booking timestamps without an offset are Dhaka-local (UTC+06:00). Use ISO date strings for date-only values. Resource collections may be Laravel paginated responses or explicit data arrays; follow each operation in OpenAPI.

Handle 401, 403, 409, 422 and 429 separately. Show field validation errors near inputs. Hide actions by role for convenience while relying on server enforcement. Disable duplicate submit taps. Preserve the same serialized request body and idempotency key on network retry.

## Offline and notifications

Cache only permitted lists with a visible last-refreshed time. Never confirm bookings, approve requests or post payments offline. Expired cached availability is not a reservation. Optional staff push notifications can later navigate to the booking detail screen; guest confirmation remains a backend responsibility.

## Mobile verification

Test staff/admin restrictions, token expiry/revocation, cancellation, multi-room conflict, poor connectivity and repeated submission. Test Bangla fonts, text scaling, portrait/landscape and accessibility. Run integration tests against a staging API, never the live rest-house schema.

## Booking documents, sharing and appearance

Download `/bookings/{id}/exports/pdf` or `/exports/jpg` with Dio responseType.bytes and bearer authorization. Save to an app-private temporary file, then use the platform share sheet for WhatsApp/email attachments. Never share the bearer token or authenticated URL with guests. Use `/guest-confirmation` for an in-app guest-safe preview and `/share-options` for text composers. Server `/share` queues delivery to the booking's saved guest and requires the current version/idempotency key; HTTP 202 is not delivery confirmation. Email permits PDF/JPG; WhatsApp uses an approved provider template. Refresh `/shares` for delivery status. SMTP/Twilio and workers must be configured.

Read `/auth/preferences` after login and PATCH theme/language as the user changes them. A `system` theme follows device appearance. Responsive layout and icons are implemented by Flutter widgets. Use `/auth/forgot-password` and `/auth/reset-password` for recovery, and re-authenticate after a reset.

## Implemented Flutter client

The Android/iOS/web Flutter client now lives at G:\apps-development\coxbanglo. Its README.md contains local connection and build instructions; docs/API-MAPPING.md maps each screen to this API, and docs/VERIFICATION.md records tested builds and deployment limits. Flutter web uses port 8085 locally. Configure FLUTTER_WEB_ORIGINS for deployed web origins; production mobile/web builds require an HTTPS API.

